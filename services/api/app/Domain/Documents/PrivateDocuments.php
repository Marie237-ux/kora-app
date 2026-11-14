<?php

namespace App\Domain\Documents;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrivateDocuments
{
    public function __construct(private DocumentScanner $scanner) {}

    public function authorize(User $actor, string $purpose, bool $write): void
    {
        $permissions = match ($purpose) {
            'accounting_evidence' => [$write ? 'accounting.write' : 'accounting.read'],
            'supplier_contract', 'supplier_invoice' => [$write ? 'purchases.write' : 'purchases.read'],
            'payment_evidence' => $write ? ['finance.write', 'purchases.write'] : ['finance.read'],
            'stock_evidence' => $write ? ['stock.write', 'purchases.write'] : ['stock.read', 'purchases.read'],
            'customer_payment_evidence' => $write ? ['payments.refund'] : ['payments.refund', 'payments.read'],
            // Couriers add these through their mission (delegated upload); the delivery team reads them.
            'delivery_evidence' => [$write ? 'delivery.assign' : 'delivery.read'],
            // Customers attach these to their own after-sales case (delegated upload); support reads them.
            'claim_evidence' => [$write ? 'support.write' : 'support.read'],
            'partner_evidence' => [$write ? 'partners.manage' : 'partners.read'],
            // Sent by a supplier member through its portal (delegated upload); the purchasing team reads them.
            'supplier_portal' => [$write ? 'purchases.write' : 'purchases.read'],
            // Attachments of a conversation: customers through their own conversation (delegated), support here.
            'message_attachment' => [$write ? 'support.write' : 'support.read'],
            default => [],
        };
        abort_unless(array_intersect($permissions, $actor->permissions()), 403);
    }

    /**
     * $delegated: the caller already authorised this upload for a narrower reason (a courier photographing the
     * slip of a shop on their own mission); the file checks and antivirus scan still apply.
     */
    public function upload(
        UploadedFile $file,
        string $purpose,
        ?string $supplierId,
        User $actor,
        bool $delegated = false,
    ): array {
        if (! $delegated) {
            $this->authorize($actor, $purpose, true);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (
            ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ||
            $file->getSize() > 10 * 1024 * 1024
        ) {
            Money::fail('file', 'DOCUMENT_INVALID');
        }
        if (
            $mime === 'application/pdf' &&
            ! str_starts_with(file_get_contents($file->getRealPath(), false, null, 0, 8), '%PDF-')
        ) {
            Money::fail('file', 'DOCUMENT_INVALID');
        }
        $status = $this->scanner->scan($file->getRealPath());
        $id = Records::id();
        if ($status === 'infected') {
            Records::audit($actor, 'document.rejected', $id);
            Money::fail('file', 'DOCUMENT_BLOCKED');
        }
        abort_unless(in_array($status, ['clean', 'pending'], true), 503);
        $disk = config('kora.document_disk');
        $key = 'documents/'.$id;
        try {
            $stored = Storage::disk($disk)->put($key, file_get_contents($file->getRealPath()), [
                'visibility' => 'private',
                'ContentType' => 'application/octet-stream',
            ]);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless($stored, 503);
        try {
            DB::transaction(function () use ($id, $actor, $purpose, $supplierId, $mime, $file, $status, $disk, $key) {
                DB::table('private_documents')->insert([
                    'id' => $id,
                    'supplier_id' => $supplierId,
                    'uploaded_by' => $actor->id,
                    'purpose' => $purpose,
                    'disk' => $disk,
                    'object_key' => $key,
                    'mime' => $mime,
                    'bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'scan_status' => $status,
                    'scanned_at' => $status === 'clean' ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($actor, 'document.uploaded', $id, ['purpose' => $purpose, 'scan_status' => $status]);
            });
        } catch (\Throwable $error) {
            Storage::disk($disk)->delete($key);
            throw $error;
        }

        return $this->dto(Records::row('private_documents', $id, false));
    }

    public function requireClean(string $id, ?string $supplierId, array $purposes): object
    {
        $document = Records::row('private_documents', $id, false);
        if (
            $document->supplier_id !== $supplierId ||
            ! in_array($document->purpose, $purposes, true) ||
            $document->scan_status !== 'clean'
        ) {
            Money::fail('document_id', 'DOCUMENT_NOT_APPROVED');
        }

        return $document;
    }

    public function rescan(object $document, User $actor): array
    {
        $this->authorize($actor, $document->purpose, true);
        $path = $this->copyToTemporaryFile($document);
        try {
            $status = hash_equals($document->sha256, hash_file('sha256', $path))
                ? $this->scanner->scan($path)
                : 'infected';
        } finally {
            @unlink($path);
        }
        abort_unless(in_array($status, ['clean', 'pending', 'infected'], true), 503);
        DB::transaction(function () use ($document, $status, $actor) {
            Records::row('private_documents', $document->id);
            DB::table('private_documents')
                ->where('id', $document->id)
                ->update(['scan_status' => $status, 'scanned_at' => now(), 'updated_at' => now()]);
            Records::audit($actor, 'document.scanned', $document->id, ['scan_status' => $status]);
        });

        return $this->dto(Records::row('private_documents', $document->id, false));
    }

    /** $authorized: the caller checked a narrower right (a customer's own conversation attachment). */
    public function download(object $document, User $actor, bool $authorized = false)
    {
        if (! $authorized) {
            $this->authorize($actor, $document->purpose, false);
        }
        if ($document->scan_status !== 'clean') {
            Money::fail('document', 'DOCUMENT_NOT_APPROVED');
        }
        $path = $this->copyToTemporaryFile($document);
        if (! hash_equals($document->sha256, hash_file('sha256', $path))) {
            @unlink($path);
            abort(503);
        }
        $extension = match ($document->mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };

        return response()
            ->download($path, $document->id.'.'.$extension, [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
            ])
            ->deleteFileAfterSend();
    }

    private function copyToTemporaryFile(object $document): string
    {
        $directory = storage_path('app/private/scanning');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = tempnam($directory, 'kora-');
        try {
            $input = Storage::disk($document->disk)->readStream($document->object_key);
            if (! is_resource($input)) {
                throw new \RuntimeException;
            }
            $output = fopen($path, 'wb');
            try {
                stream_copy_to_stream($input, $output);
            } finally {
                fclose($input);
                fclose($output);
            }

            return $path;
        } catch (\Throwable) {
            @unlink($path);
            abort(503);
        }
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'purpose' => $row->purpose,
            'mime' => $row->mime,
            'bytes' => (int) $row->bytes,
            'sha256' => $row->sha256,
            'scan_status' => $row->scan_status,
            'scanned_at' => Records::iso($row->scanned_at),
            'created_at' => Records::iso($row->created_at),
        ];
    }
}

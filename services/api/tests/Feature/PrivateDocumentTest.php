<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class PrivateDocumentTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    public function test_original_bytes_are_private_and_downloaded_as_a_hash_checked_attachment(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $doc = $this->evidence($ctx['supplier'], 'supplier_contract');
        $record = DB::table('private_documents')->find($doc['id']);
        $original = Storage::disk('local')->get($record->object_key);
        $this->assertSame(hash('sha256', $original), $doc['sha256']);
        $this->assertArrayNotHasKey('object_key', $doc);
        $this->assertArrayNotHasKey('url', $doc);
        $response = $this->op('GET', 'documents/'.$doc['id'].'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertSame($original, file_get_contents($path));
        } finally {
            @unlink($path);
        }
        Storage::disk('local')->put($record->object_key, '%PDF-1.4 tampered');
        $this->op('GET', 'documents/'.$doc['id'].'/download')->assertStatus(503);
        $this->op('POST', 'documents/'.$doc['id'].'/rescan')
            ->assertOk()
            ->assertJsonPath('data.scan_status', 'infected');
        $this->op('GET', 'documents/'.$doc['id'].'/download')->assertUnprocessable();
    }

    public function test_infected_upload_is_rejected_and_payment_evidence_is_not_readable_by_procurement(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $doc = $this->evidence($ctx['supplier'], 'payment_evidence');
        $this->operationUser('procurement');
        $this->op('GET', 'documents/'.$doc['id'])->assertForbidden();
        $this->op('GET', 'documents/'.$doc['id'].'/download')->assertForbidden();
        $this->operationUser();
        $this->app->instance(
            DocumentScanner::class,
            new class implements DocumentScanner
            {
                public function scan(string $path): string
                {
                    return 'infected';
                }
            },
        );
        $this->post('/api/v1/operations/documents', [
            'supplier_id' => $ctx['supplier'],
            'purpose' => 'supplier_invoice',
            'file' => UploadedFile::fake()->createWithContent(
                'fixture.pdf',
                "%PDF-1.4\n% Test-only infected result\n%%EOF",
            ),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('private_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('documents'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'document.rejected')->count());
    }
}

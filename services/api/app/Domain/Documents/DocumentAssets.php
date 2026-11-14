<?php

namespace App\Domain\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Brand images kept by fingerprint: a document always renders with the exact logo it was issued with. */
final class DocumentAssets
{
    public function freezeLogo(): ?string
    {
        $path = (string) config('kora.document_logo');
        if (! is_file($path)) {
            return null;
        }
        $sha = hash_file('sha256', $path);
        if (! DB::table('document_assets')->where('sha256', $sha)->exists()) {
            $disk = (string) config('kora.document_disk');
            $key = 'documents/assets/'.$sha;
            Storage::disk($disk)->put($key, file_get_contents($path), ['visibility' => 'private']);
            DB::table('document_assets')->insertOrIgnore([
                'sha256' => $sha,
                'mime' => 'image/png',
                'disk' => $disk,
                'object_key' => $key,
                'created_at' => now(),
            ]);
        }

        return $sha;
    }

    public function dataUri(?string $sha): ?string
    {
        $asset = $sha ? DB::table('document_assets')->where('sha256', $sha)->first() : null;
        if (! $asset || ! Storage::disk($asset->disk)->exists($asset->object_key)) {
            return null;
        }

        return 'data:'.
            $asset->mime.
            ';base64,'.
            base64_encode(Storage::disk($asset->disk)->get($asset->object_key));
    }
}

<?php

namespace App\Domain\ProductRequests;

use App\Domain\Media\ImageNormalizer;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RequestPhotos
{
    public function upload(Request $http, string $requestId, UploadedFile $file): array
    {
        $requests = app(ProductRequests::class);
        $initial = $requests->owned($http, $requestId);
        $requests->open($initial);
        $image = app(ImageNormalizer::class)->normalize($file);
        $id = Records::id();
        $key = 'product-requests/'.$id.'.webp';
        $disk = config('kora.document_disk');
        try {
            $stored = Storage::disk($disk)->put($key, $image['contents'], [
                'visibility' => 'private',
                'ContentType' => 'image/webp',
            ]);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless($stored, 503);
        $keep = false;
        try {
            $result = DB::transaction(function () use ($http, $requestId, $requests, $image, $id, $key, $disk) {
                $r = $requests->owned($http, $requestId, true);
                $requests->open($r);
                $existing = DB::table('product_request_photos')
                    ->where('request_id', $requestId)
                    ->where('sha256', $image['sha256'])
                    ->first();
                if ($existing) {
                    abort_if($existing->revoked, 422);

                    return ['photo' => $this->dto($existing), 'request_version' => (int) $r->version];
                }
                if (
                    DB::table('product_request_photos')
                        ->where('request_id', $requestId)
                        ->where('revoked', false)
                        ->count() >= 8
                ) {
                    Money::fail('photos', 'REQUEST_PHOTO_LIMIT');
                }
                DB::table('product_request_photos')->insert([
                    'id' => $id,
                    'request_id' => $requestId,
                    'disk' => $disk,
                    'object_key' => $key,
                    'bytes' => $image['bytes'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'sha256' => $image['sha256'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $r = $requests->change($r, []);
                $requests->event($r, 'photo_added', $http->user(), 'customer', ['photo_id' => $id]);

                return [
                    'photo' => $this->dto(Records::row('product_request_photos', $id)),
                    'request_version' => (int) $r->version,
                ];
            }, 3);
            $keep = $result['photo']['id'] === $id;

            return $result;
        } finally {
            if (! $keep) {
                Storage::disk($disk)->delete($key);
            }
        }
    }

    public function file(object $p)
    {
        abort_if($p->revoked, 404);
        try {
            $contents = Storage::disk($p->disk)->get($p->object_key);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless(hash_equals($p->sha256, hash('sha256', $contents)), 503);

        return response($contents, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Content-Disposition' => 'inline; filename="'.$p->id.'.webp"',
        ]);
    }

    public function dto(object $p): array
    {
        return [
            'id' => $p->id,
            'width' => (int) $p->width,
            'height' => (int) $p->height,
            'bytes' => (int) $p->bytes,
            'url' => '/api/v1/product-requests/'.$p->request_id.'/photos/'.$p->id,
        ];
    }
}

<?php

namespace App\Domain\Media;

use App\Domain\Search\PhotoIndexer;
use App\Domain\Shared\BusinessEvents;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MediaLibrary
{
    public function upload(UploadedFile $file, array $alt, User $actor): array
    {
        // 2400 px so full-width site backgrounds stay sharp on large screens.
        $image = app(ImageNormalizer::class)->normalize($file, output: 2400);
        $id = (string) Str::uuid();
        $key = 'catalogue/'.$id.'.webp';
        $disk = config('kora.media_disk');
        $storage = Storage::disk($disk);
        // L'appel au stockage objet ne se fait pas sous un verrou de base.
        try {
            $stored = $storage->put($key, $image['contents'], [
                'visibility' => 'private',
                'ContentType' => 'image/webp',
            ]);
        } catch (\Throwable) {
            abort(503);
        }
        if (! $stored) {
            abort(503);
        }
        try {
            DB::transaction(function () use ($actor, $id, $key, $disk, $image, $alt) {
                DB::table('media_assets')->insert([
                    'id' => $id,
                    'uploaded_by' => $actor->id,
                    'disk' => $disk,
                    'object_key' => $key,
                    'mime' => $image['mime'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'bytes' => $image['bytes'],
                    'sha256' => $image['sha256'],
                    'alt' => json_encode($alt, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                app(BusinessEvents::class)->audit($actor, 'media.uploaded', $id, ['bytes' => $image['bytes']]);
            });
        } catch (\Throwable $error) {
            $storage->delete($key);
            throw $error;
        }

        return $this->dto(DB::table('media_assets')->find($id));
    }

    public function canRead(object $media, User $user): bool
    {
        return $media->is_public ||
            (int) $media->uploaded_by === $user->id ||
            in_array('owner', $user->roles ?? [], true);
    }

    public function dto(object $media): array
    {
        return [
            'id' => $media->id,
            'width' => $media->width,
            'height' => $media->height,
            'bytes' => $media->bytes,
            'alt' => json_decode($media->alt, true),
            'is_public' => (bool) $media->is_public,
            'revoked' => (bool) $media->revoked,
            'version' => (int) $media->version,
            'url' => $media->is_public && ! $media->revoked
                    ? $this->publicPath($media->id)
                    : '/api/v1/operations/media/'.$media->id.'/file',
        ];
    }

    public function publicPath(string $id): string
    {
        return '/api/v1/media/'.$id.'/public';
    }

    public function approvedPath(?string $path): bool
    {
        if ($path === '/brand/logo.png') {
            return true;
        }
        if (
            ! $path ||
            ! preg_match('~^/api/v1/media/([a-f0-9-]{36})/public$~', $path, $match) ||
            ! Str::isUuid($match[1])
        ) {
            return false;
        }

        return DB::table('media_assets')
            ->where('id', $match[1])
            ->where('is_public', true)
            ->where('revoked', false)
            ->exists();
    }

    /**
     * $authorized: the caller already checked a narrower right (a review moderator approving a customer photo
     * of that review); version and revocation checks still apply.
     */
    public function setPublic(string $id, int $version, bool $public, User $actor, bool $authorized = false): array
    {
        return DB::transaction(function () use ($id, $version, $public, $actor, $authorized) {
            $media = DB::table('media_assets')->where('id', $id)->lockForUpdate()->first();
            abort_unless($media, 404);
            abort_unless($authorized || $this->canRead($media, $actor), 403);
            abort_unless((int) $media->version === $version, 409);
            abort_if($media->revoked && $public, 422);
            DB::table('media_assets')
                ->where('id', $id)
                ->update([
                    'is_public' => $public,
                    'rights_confirmed_at' => $public ? now() : $media->rights_confirmed_at,
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);
            app(PhotoIndexer::class)->media($id);
            app(BusinessEvents::class)->audit($actor, $public ? 'media.published' : 'media.withdrawn', $id, [
                'version' => $version + 1,
            ]);

            return $this->dto(DB::table('media_assets')->find($id));
        }, 3);
    }

    public function file(object $media)
    {
        try {
            $stream = Storage::disk($media->disk)->readStream($media->object_key);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless(is_resource($stream), 503);

        return response()->stream(
            function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $media->mime,
                'Content-Length' => (string) $media->bytes,
                'Content-Disposition' => 'inline; filename="'.$media->id.'.webp"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}

<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ImageNormalizer
{
    /** Phone photos for search may be larger than catalogue uploads; they are reduced the same safe way. */
    public function normalize(
        UploadedFile $file,
        int $maxBytes = 2 * 1024 * 1024,
        int $maxPixels = 8_000_000,
        int $output = 1600,
    ): array {
        $error = fn () => ValidationException::withMessages(['file' => __('catalogue.INVALID_IMAGE')]);
        if ($file->getSize() > $maxBytes) {
            throw $error();
        }
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw $error();
        }
        $size = @getimagesize($path);
        if (
            ! $size ||
            $size[0] < 1 ||
            $size[1] < 1 ||
            $size[0] > 4096 ||
            $size[1] > 4096 ||
            $maxPixels < $size[0] * $size[1]
        ) {
            throw $error();
        }
        $image = @imagecreatefromstring(file_get_contents($path));
        if (! $image) {
            throw $error();
        }
        try {
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                if ($orientation === 4) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
                $rotation = match ($orientation) {
                    3 => 180,
                    5, 8 => 90,
                    6, 7 => -90,
                    default => 0,
                };
                if ($rotation) {
                    $rotated = imagerotate($image, $rotation, 0);
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $ratio = min(1, $output / max($width, $height));
            $targetWidth = max(1, (int) round($width * $ratio));
            $targetHeight = max(1, (int) round($height * $ratio));
            $output = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($output, false);
            imagesavealpha($output, true);
            imagefill($output, 0, 0, imagecolorallocatealpha($output, 0, 0, 0, 127));
            imagecopyresampled($output, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            try {
                $encoded = imagewebp($output, null, 85);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($output);
            }
            if (! $encoded || ! $bytes) {
                throw $error();
            }

            return [
                'contents' => $bytes,
                'mime' => 'image/webp',
                'width' => $targetWidth,
                'height' => $targetHeight,
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        } finally {
            imagedestroy($image);
        }
    }
}

<?php

namespace App\Services;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/**
 * Produces the stamped derivative used in the client report: capture time,
 * coordinates and visit number burned into the image.
 *
 * The stamp is deliberately ASCII (digits and short Latin labels) so it renders
 * with GD's built-in font and carries no TTF dependency. The Arabic report text
 * lives in the PDF around the image, not inside it.
 *
 * Note what this is and is not: the stamp makes tampering visible in the deliverable.
 * It is not cryptographic proof — that is what original_hash is for.
 */
class PhotoStamper
{
    public function stamp(MediaFile $media): ?string
    {
        if ($media->original_path === null) {
            return null;
        }

        $disk = Storage::disk('local');
        $source = $disk->path($media->original_path);

        if (! file_exists($source) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = $this->load($source, $media->mime);

        if ($image === null) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $barHeight = max(28, (int) ($height * 0.06));

        $overlay = imagecreatetruecolor($width, $barHeight);
        imagefilledrectangle($overlay, 0, 0, $width, $barHeight, imagecolorallocate($overlay, 0, 0, 0));
        imagecopymerge($image, $overlay, 0, $height - $barHeight, 0, 0, $width, $barHeight, 55);
        imagedestroy($overlay);

        $white = imagecolorallocate($image, 255, 255, 255);
        $lines = $this->stampLines($media);

        $y = $height - $barHeight + 6;
        foreach ($lines as $line) {
            imagestring($image, 4, 8, $y, $line, $white);
            $y += 14;
        }

        $target = 'media/derived/' . $media->client_media_id . '.jpg';
        $absoluteTarget = $disk->path($target);

        if (! is_dir(dirname($absoluteTarget))) {
            mkdir(dirname($absoluteTarget), 0775, true);
        }

        imagejpeg($image, $absoluteTarget, 85);
        imagedestroy($image);

        return $target;
    }

    /** @return array<int, string> */
    private function stampLines(MediaFile $media): array
    {
        $when = $media->captured_at?->format('Y-m-d H:i') ?? '-';
        $coords = ($media->lat !== null && $media->lng !== null)
            ? sprintf('%.5f, %.5f', (float) $media->lat, (float) $media->lng)
            : 'no fix';

        return [
            sprintf('DARAK  visit #%d  %s', $media->visit_id, $when),
            sprintf('GPS %s  src:%s', $coords, $media->declared_source),
        ];
    }

    private function load(string $path, ?string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => @imagecreatefromjpeg($path),
        };

        if (! $image) {
            $image = @imagecreatefromstring((string) file_get_contents($path));
        }

        return $image ?: null;
    }
}

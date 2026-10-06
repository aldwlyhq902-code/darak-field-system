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
    public const IMAGE_TOO_LARGE = 'IMAGE_TOO_LARGE';

    public const IMAGE_UNREADABLE = 'IMAGE_UNREADABLE';

    /**
     * Inspect the header only. GD allocates width x height x 4 bytes on decode,
     * so a 25 MB file declaring 30000x30000 pixels asks for 3.6 GB — a single
     * upload could take the worker down. Nothing is decoded past these limits.
     */
    public function dimensionProblem(string $path): ?string
    {
        $info = @getimagesize($path);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return self::IMAGE_UNREADABLE;
        }

        [$width, $height] = $info;

        if ($width > self::maxSide() || $height > self::maxSide() || $width * $height > self::maxPixels()) {
            return self::IMAGE_TOO_LARGE;
        }

        return null;
    }

    public static function maxPixels(): int
    {
        return (int) config('darak.media.max_image_pixels', 40_000_000);
    }

    public static function maxSide(): int
    {
        return (int) config('darak.media.max_image_side', 12_000);
    }

    public function stamp(MediaFile $media): ?string
    {
        if ($media->original_path === null) {
            return null;
        }

        $disk = Storage::disk(config('darak.files_disk', 'local'));
        $source = $disk->path($media->original_path);

        if (! file_exists($source) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        // Unreadable or oversized: keep the verified original, skip the stamp.
        if ($this->dimensionProblem($source) !== null) {
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

        $target = 'media/derived/'.$media->client_media_id.'.jpg';
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

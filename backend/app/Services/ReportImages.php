<?php

namespace App\Services;

use App\Support\PrivateFiles;

/**
 * Images for the visit PDF: report-sized JPEG derivatives, made on demand and
 * cached on the files disk next to the evidence they come from.
 *
 * The report used to embed every photo at full size (a phone photo is
 * 4000x3000, several MB) as base64 inside the HTML. A few real photos pushed
 * the HTML past `pcre.backtrack_limit` (1,000,000 by default) and mPDF refused
 * to render; past that, memory went next. Now:
 *  - each photo is downscaled once to at most MAX_SIDE px on its longest side
 *    at JPEG quality QUALITY — printed 78 mm wide that is still ~450 dpi;
 *  - the derivative is cached under DIRECTORY and reused by every later
 *    render (and deleted with its source by the retention purge);
 *  - the builder hands the bytes to mPDF through `imageVars`, so no image
 *    data goes through the HTML parser at all.
 */
class ReportImages
{
    public const MAX_SIDE = 1400;

    public const QUALITY = 80;

    public const DIRECTORY = 'media/report';

    public function __construct(private readonly PhotoStamper $stamper) {}

    /** Where the report derivative of $sourcePath is cached. */
    public static function cachePathFor(string $sourcePath): string
    {
        return self::DIRECTORY.'/'.sha1($sourcePath).'-'.self::MAX_SIDE.'q'.self::QUALITY.'.jpg';
    }

    /**
     * JPEG bytes sized for the report, or null when the source is missing or
     * cannot be read safely (corrupt, or dimensions past the decode limits).
     */
    public function jpeg(string $sourcePath): ?string
    {
        $disk = PrivateFiles::disk();
        $cachePath = self::cachePathFor($sourcePath);

        if ($disk->exists($cachePath)) {
            return (string) $disk->get($cachePath);
        }

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        $derived = $this->downscale((string) $disk->get($sourcePath));

        if ($derived !== null) {
            $disk->put($cachePath, $derived);
        }

        return $derived;
    }

    /**
     * Small enough to be its own derivative? Otherwise decode once, resample,
     * re-encode, and free each bitmap as soon as it is no longer needed: a
     * 4000x3000 decode is ~48 MB, so only one may be alive at a time.
     */
    public function downscale(string $bytes): ?string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($info === false || $this->stamper->dimensionProblemForBytes($bytes) !== null) {
            return null;
        }

        [$width, $height] = $info;
        $isJpeg = ($info[2] ?? null) === IMAGETYPE_JPEG;

        if ($isJpeg && max($width, $height) <= self::MAX_SIDE) {
            return $bytes;
        }

        if (! function_exists('imagecreatetruecolor')) {
            // No GD: a JPEG can still go in as-is (mPDF embeds JPEG without
            // decoding it); anything else would be decoded by mPDF at full size.
            return $isJpeg ? $bytes : null;
        }

        $source = @imagecreatefromstring($bytes);
        unset($bytes);

        if ($source === false) {
            return null;
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        // A transparent PNG becomes white, not black, as a JPEG.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($target, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($target);

        return $jpeg === '' ? null : $jpeg;
    }
}

<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * The disk holding private uploads: employee and vehicle documents, inspection
 * and emergency photos, subcontractor documents, sales attachments and visit
 * reports. Configured by `darak.files_disk` so production can move them to an
 * S3-compatible bucket without touching the controllers.
 *
 * These files are never given a public URL. They are served only by
 * controllers that authorise the request first and stream the bytes back.
 */
final class PrivateFiles
{
    public static function diskName(): string
    {
        $name = config('darak.files_disk', 'local');

        return is_string($name) && $name !== '' ? $name : 'local';
    }

    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::diskName());

        return $disk;
    }
}

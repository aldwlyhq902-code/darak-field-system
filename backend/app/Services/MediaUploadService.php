<?php

namespace App\Services;

use App\Exceptions\MediaUploadRejected;
use App\Models\MediaFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Resumable evidence upload: the file I/O behind MediaController.
 *
 * A connection dropped at 60% resumes from the byte offset instead of restarting —
 * technicians work in basements and kitchens, and re-sending a 3MB photo over a bad
 * link is how evidence gets abandoned.
 *
 * The ORIGINAL file is kept with its sha256; the stamped copy used in the report is
 * a derivative. Keeping only the stamped image would destroy the audit trail.
 *
 * Every refusal is a MediaUploadRejected carrying the stable code the app acts on.
 */
class MediaUploadService
{
    public const CHUNK_LIMIT = 8 * 1024 * 1024;

    public function __construct(
        private readonly PhotoStamper $stamper,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Append one chunk at the declared offset.
     *
     * @return array<string, mixed>
     */
    public function appendChunk(MediaFile $media, int $offset, string $bytes): array
    {
        if ($media->discarded_at !== null) {
            throw $this->discarded();
        }

        if ($media->upload_state === 'complete') {
            return ['code' => 'ALREADY_COMPLETE', 'uploaded_bytes' => $media->uploaded_bytes];
        }

        if ($bytes === '') {
            throw new MediaUploadRejected('EMPTY_CHUNK', 'No bytes received.', 422);
        }

        $chunkBytes = strlen($bytes);

        if ($chunkBytes > self::CHUNK_LIMIT) {
            throw new MediaUploadRejected('CHUNK_TOO_LARGE', 'Chunk exceeds 8MB.', 413);
        }

        $declaredBytes = (int) $media->total_bytes;
        $maxBytes = $this->maxBytes();

        if ($declaredBytes < 1 || $declaredBytes > $maxBytes) {
            throw new MediaUploadRejected(
                'INVALID_MEDIA_SIZE',
                'This evidence item has no valid declared size. Register it again.',
                422,
            );
        }

        if ($offset < 0 || $offset + $chunkBytes > $declaredBytes || $offset + $chunkBytes > $maxBytes) {
            throw new MediaUploadRejected(
                'UPLOAD_LIMIT_EXCEEDED',
                'The chunk would exceed the declared or permitted evidence size.',
                413,
                ['total_bytes' => $declaredBytes],
            );
        }

        // Offset mismatch is not an error the client should guess about: tell it
        // exactly where the server is and let it resume from there.
        if ($offset !== (int) $media->uploaded_bytes) {
            throw $this->offsetMismatch($media);
        }

        $partPath = $this->partPath($media);
        $disk = $this->disk();

        if (! $disk->exists($partPath)) {
            $disk->put($partPath, '');
        }

        $handle = fopen($disk->path($partPath), 'c+b');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new MediaUploadRejected('UPLOAD_BUSY', 'Could not lock the upload. Retry this chunk.', 409);
        }

        try {
            // A second request may have passed the fast offset check while waiting
            // for this file lock. Re-read only after exclusive ownership.
            $media->refresh();
            if ($media->discarded_at !== null) {
                throw $this->discarded();
            }
            if ($media->upload_state === 'complete') {
                return ['code' => 'ALREADY_COMPLETE', 'uploaded_bytes' => $media->uploaded_bytes];
            }
            if ($offset !== (int) $media->uploaded_bytes) {
                throw $this->offsetMismatch($media);
            }

            if (fseek($handle, $offset) !== 0 || fwrite($handle, $bytes) !== $chunkBytes || ! fflush($handle)) {
                ftruncate($handle, $offset);

                throw new MediaUploadRejected('WRITE_FAILED', 'The chunk could not be stored. Retry it.', 500);
            }

            // Compare-and-set prevents a stale request from advancing the counter.
            // A discard landing mid-write still wins through the null predicate.
            $claimed = MediaFile::whereKey($media->getKey())
                ->whereNull('discarded_at')
                ->where('uploaded_bytes', $offset)
                ->where('upload_state', '!=', 'complete')
                ->update([
                    'uploaded_bytes' => $offset + $chunkBytes,
                    'upload_state' => 'uploading',
                    'attempts' => $media->attempts + 1,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                ftruncate($handle, $offset);
                $media->refresh();

                throw $media->discarded_at !== null ? $this->discarded() : $this->offsetMismatch($media);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $media->refresh();

        return [
            'uploaded_bytes' => $media->uploaded_bytes,
            'total_bytes' => $media->total_bytes,
            'upload_state' => $media->upload_state,
        ];
    }

    /**
     * Verify the hash, store the original, and produce the stamped derivative.
     *
     * @return array<string, mixed>
     */
    public function complete(MediaFile $media, string $sha256): array
    {
        if ($media->discarded_at !== null) {
            throw $this->discarded();
        }

        $disk = $this->disk();
        $partPath = $this->partPath($media);

        if (! $disk->exists($partPath)) {
            throw new MediaUploadRejected('NO_UPLOAD', 'Nothing uploaded for this file.', 422);
        }

        $absolute = $disk->path($partPath);
        $actualBytes = filesize($absolute);
        $declaredBytes = (int) $media->total_bytes;
        $maxBytes = $this->maxBytes();

        if ($actualBytes === false || $declaredBytes < 1 || $declaredBytes > $maxBytes
            || $actualBytes !== $declaredBytes || $actualBytes > $maxBytes) {
            throw new MediaUploadRejected(
                'SIZE_MISMATCH',
                'Uploaded bytes do not match the declared evidence size.',
                422,
                ['uploaded_bytes' => $actualBytes === false ? null : $actualBytes, 'total_bytes' => $declaredBytes],
            );
        }

        $actual = hash_file('sha256', $absolute);

        if (! hash_equals(strtolower($sha256), strtolower($actual))) {
            $this->resetUpload($media, $partPath, 'sha256 mismatch');

            throw new MediaUploadRejected(
                'HASH_MISMATCH',
                'Uploaded bytes do not match the declared hash. Restart the upload.',
                422,
            );
        }

        // Read the header only; never hand GD a decompression bomb. A photo too
        // large to stamp safely is refused before it becomes evidence, so the
        // app can re-capture at a sane resolution.
        if ($this->isImageKind($media) && $this->stamper->dimensionProblem($absolute) === PhotoStamper::IMAGE_TOO_LARGE) {
            $this->resetUpload($media, $partPath, PhotoStamper::IMAGE_TOO_LARGE);

            throw new MediaUploadRejected(
                PhotoStamper::IMAGE_TOO_LARGE,
                'The image resolution exceeds the permitted limit. Capture it again at a lower resolution.',
                422,
                ['max_pixels' => PhotoStamper::maxPixels(), 'max_side' => PhotoStamper::maxSide()],
            );
        }

        $finalPath = $this->finalPath($media);
        $disk->move($partPath, $finalPath);

        // Conditional and atomic. Reading discarded_at earlier and writing here
        // left a window: a discard could land between the two, and the row ended
        // up complete AND discarded — with the gate ignoring evidence that had
        // actually arrived.
        $claimed = MediaFile::whereKey($media->getKey())
            ->whereNull('discarded_at')
            ->update([
                'original_path' => $finalPath,
                'original_hash' => $actual,
                'upload_state' => 'complete',
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // Discarded while these bytes were being verified. Drop the file
            // rather than leaving an orphan on disk no record points to.
            $disk->delete($finalPath);

            throw $this->discarded();
        }

        $media->refresh();

        if (str_starts_with((string) $media->kind, 'photo')) {
            $media->forceFill(['derived_path' => $this->stamper->stamp($media)])->save();
        }

        return [
            'client_media_id' => $media->client_media_id,
            'upload_state' => $media->upload_state,
            'original_hash' => $media->original_hash,
            'has_derived' => $media->derived_path !== null,
        ];
    }

    /**
     * Drop a file that will never upload, optionally naming its replacement.
     *
     * Without this a failed upload blocked the close permanently: retaking added
     * a second row while the first went on raising UPLOADS_FAILED, and there was
     * no endpoint to remove it. The record is kept, not deleted — who dropped
     * what and why is part of the trail.
     *
     * @return array<string, mixed>
     */
    public function discard(MediaFile $media, string $reason, ?string $supersededBy, int $actorId): array
    {
        $replacement = $supersededBy !== null
            ? MediaFile::where('client_media_id', $supersededBy)
                ->where('visit_id', $media->visit_id)
                ->first()
            : null;

        // Re-read under a row lock. Without it a chunk arriving at the same
        // moment could take the file to 'complete' while this marks it
        // discarded, leaving a row that is both — and CloseGate would ignore
        // evidence that had in fact landed.
        $outcome = DB::transaction(function () use ($media, $reason, $actorId, $replacement) {
            $locked = MediaFile::whereKey($media->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->upload_state === 'complete') {
                return null;
            }

            $locked->forceFill([
                'discarded_at' => now(),
                'discard_reason' => $reason,
                'discarded_by' => $actorId,
                'superseded_by_id' => $replacement?->id,
                'upload_state' => 'discarded',
            ])->save();

            return $locked;
        });

        if ($outcome === null) {
            throw new MediaUploadRejected(
                'CANNOT_DISCARD_COMPLETE',
                'Evidence that uploaded successfully cannot be discarded from the app.',
                422,
            );
        }

        $this->audit->record('media.discarded', $outcome, null, [
            'reason' => $reason,
            'superseded_by' => $replacement?->client_media_id,
        ]);

        return [
            'client_media_id' => $outcome->client_media_id,
            'discarded_at' => $outcome->discarded_at->toIso8601String(),
        ];
    }

    private function resetUpload(MediaFile $media, string $partPath, string $error): void
    {
        $media->forceFill([
            'upload_state' => 'failed',
            'last_error' => $error,
            'uploaded_bytes' => 0,
        ])->save();
        $this->disk()->delete($partPath);
    }

    private function isImageKind(MediaFile $media): bool
    {
        return str_starts_with((string) $media->kind, 'photo') || $media->kind === 'signature';
    }

    private function discarded(): MediaUploadRejected
    {
        return new MediaUploadRejected('MEDIA_DISCARDED', 'This file was discarded. Capture a replacement instead.', 409);
    }

    private function offsetMismatch(MediaFile $media): MediaUploadRejected
    {
        return new MediaUploadRejected(
            'OFFSET_MISMATCH',
            'Resume from the server offset.',
            409,
            ['expected_offset' => (int) $media->uploaded_bytes],
        );
    }

    private function maxBytes(): int
    {
        return (int) config('darak.max_media_bytes', 25 * 1024 * 1024);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('darak.files_disk', 'local'));
    }

    private function partPath(MediaFile $media): string
    {
        return "media/parts/{$media->client_media_id}.part";
    }

    private function finalPath(MediaFile $media): string
    {
        $ext = match ($media->mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/mp4', 'audio/m4a' => 'm4a',
            default => 'jpg',
        };

        return "media/visits/{$media->visit_id}/{$media->client_media_id}.{$ext}";
    }
}

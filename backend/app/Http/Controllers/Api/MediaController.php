<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Services\PhotoStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Resumable evidence upload.
 *
 * A connection dropped at 60% resumes from the byte offset instead of restarting —
 * technicians work in basements and kitchens, and re-sending a 3MB photo over a bad
 * link is how evidence gets abandoned.
 *
 * The ORIGINAL file is kept with its sha256; the stamped copy used in the report is
 * a derivative. Keeping only the stamped image would destroy the audit trail.
 */
class MediaController extends Controller
{
    private const CHUNK_LIMIT = 8 * 1024 * 1024;

    public function __construct(private readonly PhotoStamper $stamper)
    {
    }

    /**
     * Ownership gate for every media route.
     *
     * The sha256 check is NOT a substitute for this. An attacker uploading bytes
     * of their own choosing also supplies the matching hash, so without an owner
     * check a technician whose visit was reassigned could overwrite the new
     * technician's evidence and the integrity check would happily agree.
     */
    private function authorizeMedia(MediaFile $media): void
    {
        $media->loadMissing('visit');

        abort_if($media->visit === null, 404);

        $this->authorize('view', $media->visit);
    }

    /** Client asks where to resume from. */
    public function status(string $clientMediaId): JsonResponse
    {
        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeMedia($media);

        return response()->json([
            'client_media_id' => $media->client_media_id,
            'upload_state' => $media->upload_state,
            'uploaded_bytes' => $media->uploaded_bytes,
            'total_bytes' => $media->total_bytes,
        ]);
    }

    /** Append one chunk at the declared offset. */
    public function chunk(Request $request, string $clientMediaId): JsonResponse
    {
        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeMedia($media);

        if ($media->upload_state === 'complete') {
            return response()->json([
                'code' => 'ALREADY_COMPLETE',
                'uploaded_bytes' => $media->uploaded_bytes,
            ]);
        }

        $offset = (int) $request->header('X-Upload-Offset', (string) $request->input('offset', 0));
        $bytes = $request->getContent();

        if ($bytes === '' || $bytes === false) {
            return response()->json(['code' => 'EMPTY_CHUNK', 'message' => 'No bytes received.'], 422);
        }

        if (strlen($bytes) > self::CHUNK_LIMIT) {
            return response()->json(['code' => 'CHUNK_TOO_LARGE', 'message' => 'Chunk exceeds 8MB.'], 413);
        }

        // Offset mismatch is not an error the client should guess about: tell it
        // exactly where the server is and let it resume from there.
        if ($offset !== (int) $media->uploaded_bytes) {
            return response()->json([
                'code' => 'OFFSET_MISMATCH',
                'message' => 'Resume from the server offset.',
                'expected_offset' => (int) $media->uploaded_bytes,
            ], 409);
        }

        $partPath = $this->partPath($media);
        $disk = Storage::disk('local');

        if (! $disk->exists($partPath)) {
            $disk->put($partPath, '');
        }

        $absolute = $disk->path($partPath);
        $handle = fopen($absolute, 'ab');
        fwrite($handle, $bytes);
        fclose($handle);

        $media->forceFill([
            'uploaded_bytes' => $media->uploaded_bytes + strlen($bytes),
            'upload_state' => 'uploading',
            'attempts' => $media->attempts + 1,
        ])->save();

        return response()->json([
            'uploaded_bytes' => $media->uploaded_bytes,
            'total_bytes' => $media->total_bytes,
            'upload_state' => $media->upload_state,
        ]);
    }

    /** Verify the hash, store the original, and produce the stamped derivative. */
    public function complete(Request $request, string $clientMediaId): JsonResponse
    {
        $data = $request->validate(['sha256' => ['required', 'string', 'size:64']]);

        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeMedia($media);

        $disk = Storage::disk('local');
        $partPath = $this->partPath($media);

        if (! $disk->exists($partPath)) {
            return response()->json(['code' => 'NO_UPLOAD', 'message' => 'Nothing uploaded for this file.'], 422);
        }

        $absolute = $disk->path($partPath);
        $actual = hash_file('sha256', $absolute);

        if (! hash_equals(strtolower($data['sha256']), strtolower($actual))) {
            $media->forceFill([
                'upload_state' => 'failed',
                'last_error' => 'sha256 mismatch',
                'uploaded_bytes' => 0,
            ])->save();
            $disk->delete($partPath);

            return response()->json([
                'code' => 'HASH_MISMATCH',
                'message' => 'Uploaded bytes do not match the declared hash. Restart the upload.',
            ], 422);
        }

        $finalPath = $this->finalPath($media);
        $disk->move($partPath, $finalPath);

        $media->forceFill([
            'original_path' => $finalPath,
            'original_hash' => $actual,
            'upload_state' => 'complete',
            'last_error' => null,
        ])->save();

        if (str_starts_with((string) $media->kind, 'photo')) {
            $media->forceFill(['derived_path' => $this->stamper->stamp($media)])->save();
        }

        return response()->json([
            'client_media_id' => $media->client_media_id,
            'upload_state' => $media->upload_state,
            'original_hash' => $media->original_hash,
            'has_derived' => $media->derived_path !== null,
        ]);
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

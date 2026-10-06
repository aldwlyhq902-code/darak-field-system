<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MediaUploadRejected;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MediaFile;
use App\Services\MediaUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumable evidence upload. The byte handling lives in MediaUploadService; this
 * controller decides WHO may read and who may write.
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaUploadService $uploads) {}

    /**
     * Read gate: anyone who may view the visit may ask for upload status.
     *
     * The sha256 check is NOT a substitute for the write gate below. An attacker
     * uploading bytes of their own choosing also supplies the matching hash, so
     * without an owner check a technician whose visit was reassigned could
     * overwrite the new technician's evidence and the integrity check would
     * happily agree.
     */
    private function authorizeRead(MediaFile $media): void
    {
        $media->loadMissing('visit');

        abort_if($media->visit === null, 404);

        $this->authorize('view', $media->visit);
    }

    /**
     * Write gate: chunk, complete and discard. Viewing a visit is not the right
     * to replace its pending evidence — back-office accounts read only. Only the
     * technician who registered the file, from the device that registered it,
     * may write. Rows registered before this was recorded fall back to the
     * visit's assigned technician (which the view policy already requires).
     */
    private function authorizeWrite(Request $request, MediaFile $media): void
    {
        $this->authorizeRead($media);

        $user = $request->user();
        $device = $request->attributes->get('device');

        $owns = $user->isTechnician() && (
            $media->registered_by_user_id === null
                ? $media->visit->assigned_user_id === $user->id
                : $media->registered_by_user_id === $user->id
        );

        // A device token must be the registering handset. Session/transient
        // auth carries no device and is held to the user check alone.
        if ($owns && $device instanceof Device && $media->registered_device_id !== null) {
            $owns = $media->registered_device_id === $device->id;
        }

        if (! $owns) {
            throw new AuthorizationException('Only the registering technician device may upload this evidence.');
        }
    }

    /** Client asks where to resume from. */
    public function status(string $clientMediaId): JsonResponse
    {
        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeRead($media);

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
        $this->authorizeWrite($request, $media);

        $offset = (int) $request->header('X-Upload-Offset', (string) $request->input('offset', 0));

        return $this->respond(fn () => $this->uploads->appendChunk($media, $offset, (string) $request->getContent()));
    }

    /** Verify the hash, store the original, and produce the stamped derivative. */
    public function complete(Request $request, string $clientMediaId): JsonResponse
    {
        $data = $request->validate([
            'sha256' => ['required', 'string', 'size:64', 'regex:/\\A[0-9a-fA-F]{64}\\z/'],
        ]);

        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeWrite($request, $media);

        return $this->respond(fn () => $this->uploads->complete($media, $data['sha256']));
    }

    /** The way out for evidence that will never upload. */
    public function discard(Request $request, string $clientMediaId): JsonResponse
    {
        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();
        $this->authorizeWrite($request, $media);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
            'superseded_by' => ['nullable', 'uuid'],
        ]);

        return $this->respond(fn () => $this->uploads->discard(
            $media,
            $data['reason'],
            $data['superseded_by'] ?? null,
            $request->user()->id,
        ));
    }

    /** @param callable(): array<string, mixed> $step */
    private function respond(callable $step): JsonResponse
    {
        try {
            return response()->json($step());
        } catch (MediaUploadRejected $rejected) {
            return response()->json($rejected->body(), $rejected->status);
        }
    }
}

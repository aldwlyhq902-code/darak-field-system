<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Services\SyncService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criterion 3: a connection dropped mid-upload resumes rather than
 * restarting, and the file is verified by hash once complete.
 */
class MediaUploadTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_interrupted_upload_resumes_from_the_server_offset(): void
    {
        $bytes = random_bytes(3000);
        $hash = hash('sha256', $bytes);
        $mediaId = $this->registerMedia(strlen($bytes));

        // First 60%, then the link drops.
        $firstChunk = substr($bytes, 0, 1800);
        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $firstChunk)
            ->assertOk()
            ->assertJsonPath('uploaded_bytes', 1800);

        // The client asks where to resume.
        $this->actingAs($this->technician)
            ->getJson("/api/v1/media/{$mediaId}/status")
            ->assertOk()
            ->assertJsonPath('uploaded_bytes', 1800);

        // Remaining 40% — not the whole file again.
        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '1800', 'CONTENT_TYPE' => 'application/octet-stream'], substr($bytes, 1800))
            ->assertOk()
            ->assertJsonPath('uploaded_bytes', 3000);

        $this->actingAs($this->technician)
            ->postJson("/api/v1/media/{$mediaId}/complete", ['sha256' => $hash])
            ->assertOk()
            ->assertJsonPath('upload_state', 'complete')
            ->assertJsonPath('original_hash', $hash);
    }

    public function test_wrong_offset_is_refused_and_tells_the_client_where_to_resume(): void
    {
        $mediaId = $this->registerMedia(1000);

        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '500', 'CONTENT_TYPE' => 'application/octet-stream'], random_bytes(100))
            ->assertStatus(409)
            ->assertJsonPath('code', 'OFFSET_MISMATCH')
            ->assertJsonPath('expected_offset', 0);
    }

    public function test_hash_mismatch_rejects_the_upload_and_resets_it(): void
    {
        $bytes = random_bytes(500);
        $mediaId = $this->registerMedia(strlen($bytes));

        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $bytes);

        $this->actingAs($this->technician)
            ->postJson("/api/v1/media/{$mediaId}/complete", ['sha256' => str_repeat('0', 64)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'HASH_MISMATCH');

        $media = MediaFile::where('client_media_id', $mediaId)->first();
        $this->assertSame('failed', $media->upload_state);
        $this->assertSame(0, (int) $media->uploaded_bytes);
    }

    public function test_registering_the_same_media_twice_creates_one_row(): void
    {
        $sync = app(SyncService::class);
        $clientMediaId = (string) Str::uuid();

        $event = $this->event('media.register', [
            'client_media_id' => $clientMediaId,
            'kind' => 'photo_before',
            'mime' => 'image/jpeg',
            'total_bytes' => 1234,
        ]);

        $sync->ingest($this->device, [$event]);
        $sync->ingest($this->device, [$event]);

        $this->assertSame(1, MediaFile::where('client_media_id', $clientMediaId)->count());
    }

    private function registerMedia(int $totalBytes): string
    {
        $clientMediaId = (string) Str::uuid();

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => $clientMediaId,
            'kind' => 'photo_before',
            'mime' => 'image/jpeg',
            'total_bytes' => $totalBytes,
            'upload_state' => 'pending',
        ]);

        return $clientMediaId;
    }
}

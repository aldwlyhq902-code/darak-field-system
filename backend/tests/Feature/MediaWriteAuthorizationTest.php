<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaFile;
use App\Services\PhotoStamper;
use App\Services\SyncService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Review findings M3 (media part) and the image-decoding DoS: viewing a visit is
 * not the right to write its evidence, and no image is decoded before its
 * declared dimensions are checked.
 */
class MediaWriteAuthorizationTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_back_office_may_read_upload_status_but_never_write_bytes(): void
    {
        $mediaId = $this->registerMedia(16);
        $bytes = random_bytes(16);

        $this->actingAs($this->owner)->getJson("/api/v1/media/{$mediaId}/status")->assertOk();

        $this->actingAs($this->owner)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->postJson("/api/v1/media/{$mediaId}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->postJson("/api/v1/media/{$mediaId}/discard", ['reason' => 'replace it'])
            ->assertForbidden();

        $media = MediaFile::where('client_media_id', $mediaId)->sole();
        $this->assertSame(0, (int) $media->uploaded_bytes);
        $this->assertNull($media->discarded_at);
    }

    public function test_registration_records_the_device_and_only_that_device_may_write(): void
    {
        $mediaId = $this->registerMedia(16);
        $media = MediaFile::where('client_media_id', $mediaId)->sole();
        $this->assertSame($this->technician->id, $media->registered_by_user_id);
        $this->assertSame($this->device->id, $media->registered_device_id);

        // Same technician, a second (approved) handset.
        $otherDevice = Device::create(['user_id' => $this->technician->id, 'device_uuid' => (string) Str::uuid()]);
        $otherToken = $this->technician->createToken('device:'.$otherDevice->device_uuid)->plainTextToken;
        $ownToken = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
        $bytes = random_bytes(16);

        $this->asToken($otherToken)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken], $bytes)
            ->assertForbidden();

        $this->asToken($ownToken)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$ownToken], $bytes)
            ->assertOk()
            ->assertJsonPath('uploaded_bytes', 16);
    }

    public function test_an_image_declaring_huge_dimensions_is_refused_without_being_decoded(): void
    {
        $bytes = $this->pngHeader(30000, 30000);
        $mediaId = $this->registerMedia(strlen($bytes));

        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$mediaId}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)
            ->assertOk();

        $this->actingAs($this->technician)
            ->postJson("/api/v1/media/{$mediaId}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'IMAGE_TOO_LARGE');

        $media = MediaFile::where('client_media_id', $mediaId)->sole();
        $this->assertSame('failed', $media->upload_state);
        $this->assertNull($media->original_path);
    }

    public function test_stamper_skips_oversized_images_and_still_stamps_normal_ones(): void
    {
        $stamper = app(PhotoStamper::class);
        Storage::disk('local')->put('probe/huge.png', $this->pngHeader(9000, 9000));
        $this->assertSame(PhotoStamper::IMAGE_TOO_LARGE, $stamper->dimensionProblem(Storage::disk('local')->path('probe/huge.png')));

        $image = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($image);
        Storage::disk('local')->put('media/visits/1/small.jpg', (string) ob_get_clean());

        $media = new MediaFile([
            'visit_id' => $this->visit->id, 'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after', 'mime' => 'image/jpeg',
        ]);
        $media->original_path = 'media/visits/1/small.jpg';
        $this->assertNotNull($stamper->stamp($media));

        $media->original_path = 'probe/huge.png';
        $this->assertNull($stamper->stamp($media));
    }

    private function registerMedia(int $bytes): string
    {
        $clientMediaId = (string) Str::uuid();
        $result = app(SyncService::class)->ingest($this->device, [
            $this->event('media.register', [
                'client_media_id' => $clientMediaId,
                'kind' => 'photo_after',
                'mime' => 'image/png',
                'total_bytes' => $bytes,
            ]),
        ]);
        $this->assertSame('accepted', $result['results'][0]['status']);

        return $clientMediaId;
    }

    /** A valid PNG signature and IHDR declaring the given size — no pixel data. */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\MediaFile;
use App\Models\Visit;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The seam between the technician app and the server.
 *
 * Every one of the four critical defects found in review lived HERE, and none of
 * the 134 earlier tests crossed it: they drove the server directly with fixtures
 * that set `checklist_instance_id` by hand instead of going through the real
 * `media.register` event the app actually sends. The fixture asserted the very
 * thing that was broken.
 *
 * So this file is deliberately written from the CLIENT's point of view: it sends
 * only what the Flutter app sends, in the order the app sends it, and never
 * inserts a row the app could not have produced.
 */
class SeamTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * The scenario the whole product exists for: a full visit captured with no
     * signal, then one sync.
     */
    public function test_a_visit_captured_offline_can_be_closed_through_the_real_client_path(): void
    {
        $secondAsset = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة العرض',
            'qr_code' => 'ASSET-TEST-2',
        ]);

        $token = $this->deviceToken();

        // --- offline capture: checklists, one photo per asset, one signature ---
        $events = [];
        $sequence = 0;
        $mediaIds = [];

        foreach ([$this->asset, $secondAsset] as $asset) {
            $events[] = $this->clientEvent('checklist.upsert', [
                'asset_id' => $asset->id,
                'status' => 'ok',
                'no_parts_used' => true,
            ], ++$sequence);

            $clientMediaId = (string) Str::uuid();
            $mediaIds[$clientMediaId] = 'photo';

            // Exactly what EvidenceStore.store() puts on the wire: asset_id, no
            // checklist_instance_id — the app has never seen a server id.
            $events[] = $this->clientEvent('media.register', [
                'client_media_id' => $clientMediaId,
                'kind' => 'photo_after',
                'mime' => 'image/jpeg',
                'total_bytes' => 64,
                'asset_id' => $asset->id,
                'declared_source' => 'camera',
            ], ++$sequence);
        }

        $signatureId = (string) Str::uuid();
        $mediaIds[$signatureId] = 'signature';
        $events[] = $this->clientEvent('media.register', [
            'client_media_id' => $signatureId,
            'kind' => 'signature',
            'mime' => 'image/png',
            'total_bytes' => 64,
            'declared_source' => 'on_screen',
        ], ++$sequence);

        foreach (['en_route', 'started', 'awaiting_close'] as $state) {
            $events[] = $this->clientEvent('visit.transition', ['to' => $state], ++$sequence);
        }

        $sync = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => $events,
        ])->assertOk();

        $this->assertSame(
            [],
            collect($sync->json('results'))->where('status', 'rejected')->pluck('message')->all(),
            'no event from a legitimate offline capture may be rejected',
        );

        // --- uploads land after the events, as they do in the app ---
        foreach ($mediaIds as $clientMediaId => $kind) {
            $this->uploadMedia($token, $clientMediaId);
        }

        // --- the close, sent once uploads are complete ---
        $close = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->clientEvent('visit.transition', ['to' => 'completed'], ++$sequence)],
        ])->assertOk();

        $result = $close->json('results.0');

        $this->assertSame(
            'accepted',
            $result['status'],
            'close was refused: ' . json_encode($result, JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame(Visit::STATE_COMPLETED, $this->visit->refresh()->state);
    }

    /**
     * The specific linchpin: a photo registered with asset_id must satisfy the
     * per-asset requirement in CloseGate.
     */
    public function test_a_photo_registered_with_asset_id_satisfies_the_close_gate_for_that_asset(): void
    {
        $token = $this->deviceToken();
        $clientMediaId = (string) Str::uuid();

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [
                $this->clientEvent('checklist.upsert', [
                    'asset_id' => $this->asset->id,
                    'status' => 'ok',
                    'no_parts_used' => true,
                ], 1),
                $this->clientEvent('media.register', [
                    'client_media_id' => $clientMediaId,
                    'kind' => 'photo_after',
                    'mime' => 'image/jpeg',
                    'total_bytes' => 64,
                    'asset_id' => $this->asset->id,
                ], 2),
            ],
        ])->assertOk();

        $media = MediaFile::where('client_media_id', $clientMediaId)->firstOrFail();

        $this->assertNotNull(
            $media->checklist_instance_id,
            'the server must bind the photo to the asset checklist the app named',
        );
        $this->assertSame($this->asset->id, $media->checklistInstance->asset_id);

        $this->uploadMedia($token, $clientMediaId);

        $blockers = collect(
            $this->asToken($token)
                ->getJson("/api/v1/visits/{$this->visit->id}/close-blockers")
                ->json('blockers')
        )->pluck('code');

        $this->assertNotContains('ASSET_PHOTO_MISSING', $blockers);
    }

    /**
     * A close sent while uploads are still pending must be refused in a way the
     * client can recover from — the blocker list has to name the pending uploads,
     * not a generic refusal.
     */
    public function test_close_before_uploads_finish_is_refused_with_a_recoverable_reason(): void
    {
        $token = $this->deviceToken();
        $clientMediaId = (string) Str::uuid();

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [
                $this->clientEvent('checklist.upsert', [
                    'asset_id' => $this->asset->id, 'status' => 'ok', 'no_parts_used' => true,
                ], 1),
                $this->clientEvent('media.register', [
                    'client_media_id' => $clientMediaId, 'kind' => 'photo_after',
                    'mime' => 'image/jpeg', 'total_bytes' => 64, 'asset_id' => $this->asset->id,
                ], 2),
                $this->clientEvent('visit.transition', ['to' => 'en_route'], 3),
                $this->clientEvent('visit.transition', ['to' => 'started'], 4),
                $this->clientEvent('visit.transition', ['to' => 'awaiting_close'], 5),
            ],
        ])->assertOk();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->clientEvent('visit.transition', ['to' => 'completed'], 6)],
        ])->assertOk();

        $result = $response->json('results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('VISIT_CLOSE_BLOCKED', $result['code']);

        $codes = collect($result['blockers'])->pluck('code');
        $this->assertTrue(
            $codes->contains('UPLOADS_PENDING') || $codes->contains('ASSET_PHOTO_MISSING'),
            'the refusal must name the pending evidence so the client knows to retry after upload',
        );

        // And the visit is untouched — not half-closed.
        $this->assertSame(Visit::STATE_AWAITING_CLOSE, $this->visit->refresh()->state);
    }

    /** The blockers computed during a refused close must survive for the supervisor. */
    public function test_a_refused_close_persists_its_blockers_for_the_supervisor(): void
    {
        $token = $this->deviceToken();

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [
                $this->clientEvent('visit.transition', ['to' => 'en_route'], 1),
                $this->clientEvent('visit.transition', ['to' => 'started'], 2),
                $this->clientEvent('visit.transition', ['to' => 'awaiting_close'], 3),
                $this->clientEvent('visit.transition', ['to' => 'completed'], 4),
            ],
        ])->assertOk();

        $this->assertNotEmpty(
            $this->visit->refresh()->close_blockers,
            'the supervisor must see the same reasons the technician saw',
        );
    }

    private function deviceToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');
    }

    /** @param array<string, mixed> $payload */
    private function clientEvent(string $type, array $payload, int $sequence): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'visit_id' => $this->visit->id,
            'event_type' => $type,
            'payload' => $payload,
            'device_timestamp' => now()->addSeconds($sequence)->toIso8601String(),
            'monotonic_offset_ms' => $sequence * 1000,
            'last_trusted_server_time' => now()->toIso8601String(),
            'sequence' => $sequence,
            'source' => 'offline',
        ];
    }

    private function uploadMedia(string $token, string $clientMediaId): void
    {
        $bytes = random_bytes(64);

        // Passing an explicit $server array replaces the default headers, so the
        // bearer token has to be put back in by hand.
        $this->asToken($token)->call(
            'POST',
            "/api/v1/media/{$clientMediaId}/chunk",
            [], [], [],
            [
                'HTTP_X_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE' => 'application/octet-stream',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT' => 'application/json',
            ],
            $bytes,
        )->assertOk();

        $this->asToken($token)
            ->postJson("/api/v1/media/{$clientMediaId}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertOk();
    }
}

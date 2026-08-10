<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ChecklistInstance;
use App\Models\MediaFile;
use App\Models\StockMove;
use App\Models\User;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\InventoryService;
use App\Services\SyncService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The third review. Two findings were the SAME regression as the second round,
 * reappearing in the path I had not walked: the signature, and evidence that
 * cannot be discarded.
 */
class ThirdReviewTest extends DarakTestCase
{
    public function test_a_signature_still_uploading_is_transient_like_a_photo(): void
    {
        $this->inspectFirstAsset();

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'uploading',
        ]);

        $blockers = app(CloseGate::class)->blockers($this->visit->refresh());
        $codes = collect($blockers)->pluck('code');

        $this->assertContains('SIGNATURE_UPLOAD_PENDING', $codes);
        $this->assertNotContains('SIGNATURE_MISSING', $codes);

        $this->assertTrue(
            SyncService::blockersAreTransient($blockers),
            'the same rule as photos — in flight lands by itself',
        );
    }

    public function test_a_failed_signature_is_permanent_and_names_the_remedy(): void
    {
        $this->inspectFirstAsset();

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'failed',
        ]);

        $codes = collect(app(CloseGate::class)->blockers($this->visit->refresh()))->pluck('code');

        $this->assertContains('SIGNATURE_UPLOAD_FAILED', $codes);
        $this->assertFalse(SyncService::blockersAreTransient(
            app(CloseGate::class)->blockers($this->visit->refresh())
        ));
    }

    // --- evidence that will never upload has a way out ----------------------

    public function test_a_discarded_file_stops_blocking_the_close(): void
    {
        Storage::fake('local');

        $instance = $this->inspectFirstAsset();

        // The photo that failed, and the retake that replaced it.
        $dead = MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'checklist_instance_id' => $instance->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'failed',
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'checklist_instance_id' => $instance->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('a', 64),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('b', 64),
        ]);

        // Before discarding, the dead file still blocks even though the retake landed.
        $this->assertContains(
            'UPLOADS_FAILED',
            collect(app(CloseGate::class)->blockers($this->visit->refresh()))->pluck('code'),
        );

        $token = $this->deviceToken();

        $this->asToken($token)
            ->postJson("/api/v1/media/{$dead->client_media_id}/discard", [
                'reason' => 'أُعيد الالتقاط بعد فشل الرفع',
            ])
            ->assertOk();

        $this->assertTrue(
            app(CloseGate::class)->passes($this->visit->refresh()),
            'a replaced file must stop holding the visit hostage',
        );
    }

    public function test_discarding_keeps_the_record_rather_than_deleting_it(): void
    {
        $this->inspectFirstAsset();

        $dead = MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'failed',
        ]);

        $token = $this->deviceToken();

        $this->asToken($token)
            ->postJson("/api/v1/media/{$dead->client_media_id}/discard", ['reason' => 'تالفة'])
            ->assertOk();

        $dead->refresh();

        $this->assertNotNull($dead->discarded_at);
        $this->assertSame('تالفة', $dead->discard_reason);
        $this->assertSame($this->technician->id, $dead->discarded_by);
    }

    public function test_successfully_uploaded_evidence_cannot_be_discarded(): void
    {
        $good = MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('c', 64),
        ]);

        $this->asToken($this->deviceToken())
            ->postJson("/api/v1/media/{$good->client_media_id}/discard", ['reason' => 'أريد إخفاءها'])
            ->assertStatus(422);

        $this->assertNull($good->refresh()->discarded_at);
    }

    // --- the phone is told what the SERVER requires --------------------------

    public function test_bootstrap_sends_the_frozen_requirement_not_todays_site_assets(): void
    {
        $scheduledFor = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة مجدولة',
            'qr_code' => 'ASSET-SCHEDULED',
        ]);

        $this->visit->forceFill([
            'required_asset_ids' => [$this->asset->id, $scheduledFor->id],
        ])->save();

        // Added to the site AFTER scheduling: outside the snapshot, so the phone
        // must not start demanding it.
        Asset::create([
            'site_id' => $this->site->id,
            'type' => 'split_ac',
            'name' => 'وحدة رُكّبت لاحقاً',
            'qr_code' => 'ASSET-LATER',
        ]);

        $payload = $this->asToken($this->deviceToken())
            ->getJson('/api/v1/sync/bootstrap')
            ->assertOk()
            ->json('visits.0.assets');

        $names = collect($payload)->pluck('name');

        $this->assertCount(2, $payload);
        $this->assertContains('ثلاجة مجدولة', $names);
        $this->assertNotContains('وحدة رُكّبت لاحقاً', $names);
    }

    public function test_a_reactive_order_for_one_unit_requires_only_that_unit(): void
    {
        Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة أخرى',
            'qr_code' => 'ASSET-OTHER',
        ]);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,
                'asset_id' => $this->asset->id,
                'type' => 'reactive',
                'title' => 'بلاغ عن وحدة واحدة',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHas('ok');

        $created = Visit::latest('id')->first();

        $this->assertSame([$this->asset->id], $created->required_asset_ids);
    }

    // --- returns of one issue are serialised together ------------------------

    public function test_two_returns_of_one_issue_to_different_vehicles_cannot_exceed_it(): void
    {
        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $secondVehicle = \App\Models\Vehicle::create(['plate' => 'SECOND-1', 'assigned_user_id' => $this->technician->id]);
        $secondStock = \App\Models\StockLocation::create([
            'type' => \App\Models\StockLocation::TYPE_VEHICLE,
            'name' => 'Vehicle 2',
            'vehicle_id' => $secondVehicle->id,
        ]);

        $inv->returnFromVisit((string) Str::uuid(), $issue, 2, $this->vehicleStock->id);

        // Same issue, different destination — it used to take a different lock
        // and read a stale total, so both returns passed.
        $this->expectExceptionMessage('only 0.000 of that issue remains');
        $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $secondStock->id);
    }

    // --- a disabled back-office account loses the panel ---------------------

    public function test_disabling_an_admin_ends_their_panel_session(): void
    {
        $admin = User::create([
            'name' => 'إداري',
            'email' => 'admin3@test.local',
            'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')->get(route('panel.board'))->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        // The cookie session survived deactivation; only the API tokens were cut.
        $this->actingAs($admin, 'web')
            ->get(route('panel.board'))
            ->assertRedirect(route('panel.login'));
    }

    private function inspectFirstAsset(): ChecklistInstance
    {
        $this->visit->forceFill(['required_asset_ids' => [$this->asset->id]])->save();

        $instance = ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'no_parts_used' => true,
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'checklist_instance_id' => $instance->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('d', 64),
        ]);

        return $instance;
    }

    private function deviceToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');
    }
}

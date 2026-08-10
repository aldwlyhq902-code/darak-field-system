<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ChecklistInstance;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MediaFile;
use App\Models\Site;
use App\Models\StockMove;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\InventoryService;
use App\Services\SyncService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The second adversarial review. Several findings were regressions introduced by
 * the FIRST round of repairs — a fix that looked right and quietly broke the case
 * it was written for.
 */
class SecondReviewTest extends DarakTestCase
{
    /**
     * The regression that matters most: making "retryable" strict turned the one
     * genuinely transient case into a permanent failure, because a photo still
     * uploading raises a missing-photo blocker as well as a pending-upload one.
     */
    public function test_a_photo_still_uploading_is_a_transient_refusal_not_a_permanent_one(): void
    {
        ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'no_parts_used' => true,
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'uploading',
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('a', 64),
        ]);

        $blockers = app(CloseGate::class)->blockers($this->visit->refresh());
        $codes = collect($blockers)->pluck('code');

        $this->assertContains('ASSET_PHOTO_UPLOAD_PENDING', $codes);
        $this->assertNotContains('ASSET_PHOTO_MISSING', $codes, 'in flight is not absent');

        $this->assertTrue(
            SyncService::blockersAreTransient($blockers),
            'the close must retry itself once the upload lands',
        );
    }

    public function test_an_upload_that_has_given_up_is_permanent_and_says_so(): void
    {
        ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'no_parts_used' => true,
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'failed',
        ]);

        $blockers = app(CloseGate::class)->blockers($this->visit->refresh());
        $codes = collect($blockers)->pluck('code');

        $this->assertContains('ASSET_PHOTO_UPLOAD_FAILED', $codes);
        $this->assertContains('UPLOADS_FAILED', $codes);
        $this->assertNotContains('UPLOADS_PENDING', $codes, 'a failed upload is not "about to finish"');

        $this->assertFalse(
            SyncService::blockersAreTransient($blockers),
            'retrying forever would never resolve it and never tell the technician',
        );
    }

    // --- stock cannot be manufactured ---------------------------------------

    public function test_a_return_cannot_itself_be_returned(): void
    {
        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        // Chaining returns would mint stock one hop at a time.
        $this->expectExceptionMessage('Only a part issued to a visit can be returned');
        $inv->returnFromVisit((string) Str::uuid(), $return, 1, $this->vehicleStock->id);
    }

    public function test_returns_cannot_add_up_to_more_than_was_issued(): void
    {
        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id, $this->visit);

        $inv->returnFromVisit((string) Str::uuid(), $issue, 2, $this->vehicleStock->id);

        $this->expectExceptionMessage('only 1.000 of that issue remains');
        $inv->returnFromVisit((string) Str::uuid(), $issue, 2, $this->vehicleStock->id);
    }

    public function test_the_balance_is_right_after_a_partial_return(): void
    {
        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id, $this->visit);
        $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        $this->assertEqualsWithDelta(3.0, $inv->balance($this->part->id, $this->vehicleStock->id), 0.001);
        $this->assertSame(1, StockMove::where('move_type', StockMove::VISIT_RETURN)->count());
    }

    // --- the visit must cover what it was scheduled to cover -----------------

    public function test_inspecting_one_asset_does_not_close_a_visit_that_required_five(): void
    {
        $extra = collect(range(1, 4))->map(fn ($i) => Asset::create([
            'site_id' => $this->site->id,
            'type' => 'split_ac',
            'name' => "وحدة إضافية {$i}",
            'qr_code' => "ASSET-EXTRA-{$i}",
        ]));

        $this->visit->forceFill([
            'required_asset_ids' => [$this->asset->id, ...$extra->pluck('id')->all()],
        ])->save();

        // Only the first asset is inspected, with full evidence.
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
            'original_hash' => str_repeat('a', 64),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('b', 64),
        ]);

        $blockers = app(CloseGate::class)->blockers($this->visit->refresh());
        $missing = collect($blockers)->where('code', 'ASSET_NOT_INSPECTED');

        $this->assertCount(4, $missing, 'the four untouched units must block the close');
    }

    public function test_covering_every_required_asset_closes_the_visit(): void
    {
        $second = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة العرض',
            'qr_code' => 'ASSET-SECOND',
        ]);

        $this->visit->forceFill(['required_asset_ids' => [$this->asset->id, $second->id]])->save();

        foreach ([$this->asset, $second] as $asset) {
            $instance = ChecklistInstance::create([
                'visit_id' => $this->visit->id,
                'asset_id' => $asset->id,
                'status' => 'ok',
                'no_parts_used' => true,
            ]);

            MediaFile::create([
                'visit_id' => $this->visit->id,
                'asset_id' => $asset->id,
                'checklist_instance_id' => $instance->id,
                'client_media_id' => (string) Str::uuid(),
                'kind' => 'photo_after',
                'upload_state' => 'complete',
                'original_hash' => str_repeat('a', 64),
            ]);
        }

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('b', 64),
        ]);

        $this->assertTrue(app(CloseGate::class)->passes($this->visit->refresh()));
    }

    // --- a disabled account stops working immediately ------------------------

    public function test_disabling_a_technician_kills_their_live_session(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.toggle', $this->technician))
            ->assertSessionHas('ok');

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // --- relations cannot cross clients --------------------------------------

    public function test_a_work_order_cannot_borrow_another_clients_site(): void
    {
        $otherClient = Client::create(['name' => 'عميل آخر', 'category' => 'cafe']);
        $otherSite = Site::create(['client_id' => $otherClient->id, 'name' => 'فرع آخر']);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $otherSite->id,
                'type' => 'reactive',
                'title' => 'محاولة عبور',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('site_id');

        $this->assertSame(1, Visit::count(), 'only the fixture visit exists');
    }

    public function test_a_contract_from_another_client_is_refused(): void
    {
        $otherClient = Client::create(['name' => 'عميل ثالث', 'category' => 'cafe']);
        $otherContract = Contract::create([
            'client_id' => $otherClient->id,
            'contract_no' => 'DK-OTHER-1',
            'package_code' => 'basic',
            'price_amount' => 1200,
            'starts_on' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,
                'contract_id' => $otherContract->id,
                'type' => 'preventive',
                'title' => 'عقد غريب',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('contract_id');
    }

    public function test_a_new_visit_freezes_the_assets_it_must_cover(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,
                'type' => 'preventive',
                'title' => 'جولة وقائية',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHas('ok');

        $created = Visit::latest('id')->first();

        $this->assertSame([$this->asset->id], $created->required_asset_ids);
    }
}

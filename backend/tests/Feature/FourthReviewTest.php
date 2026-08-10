<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MediaFile;
use App\Models\Site;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\RequiredAssets;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The fourth review. Its theme: a fix that lives on only one side of a boundary
 * is not a fix — the server knew the requirement, the phone was told something
 * else, and the two disagreed in the technician's hands.
 */
class FourthReviewTest extends DarakTestCase
{
    public function test_an_asset_deleted_after_scheduling_stops_being_required(): void
    {
        $doomed = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة ستُستبعد',
            'qr_code' => 'ASSET-DOOMED',
        ]);

        $this->visit->forceFill([
            'required_asset_ids' => [$this->asset->id, $doomed->id],
        ])->save();

        // The supervisor scraps the unit between scheduling and the visit.
        $doomed->delete();

        $required = app(RequiredAssets::class)->for($this->visit->refresh());

        $this->assertCount(1, $required, 'a unit that no longer exists cannot be inspected');
        $this->assertSame($this->asset->id, $required->first()->id);

        // And it must not go on blocking the close from the server side, which is
        // what left visits permanently unclosable.
        $codes = collect(app(CloseGate::class)->blockers($this->visit))->pluck('code');
        $this->assertNotContains($doomed->id, collect(app(CloseGate::class)->blockers($this->visit))->pluck('ref'));
        $this->assertContains('ASSET_NOT_INSPECTED', $codes, 'the surviving unit is still required');
    }

    public function test_the_phone_and_the_gate_are_told_the_same_thing(): void
    {
        $doomed = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'chiller',
            'name' => 'ثلاجة ستُستبعد',
            'qr_code' => 'ASSET-GONE',
        ]);

        $this->visit->forceFill([
            'required_asset_ids' => [$this->asset->id, $doomed->id],
        ])->save();

        $doomed->delete();

        $onPhone = collect(
            $this->asToken($this->deviceToken())->getJson('/api/v1/sync/bootstrap')->json('visits.0.assets')
        )->pluck('id')->sort()->values();

        $onServer = app(RequiredAssets::class)->for($this->visit->refresh())
            ->pluck('id')->sort()->values();

        $this->assertSame(
            $onServer->all(),
            $onPhone->all(),
            'one fact, one resolver — two readers is how they drifted',
        );
    }

    public function test_an_empty_requirement_means_none_not_everything(): void
    {
        $this->visit->forceFill(['required_asset_ids' => []])->save();

        Asset::create([
            'site_id' => $this->site->id,
            'type' => 'split_ac',
            'name' => 'وحدة أُضيفت لاحقاً',
            'qr_code' => 'ASSET-LATE',
        ]);

        $this->assertCount(
            0,
            app(RequiredAssets::class)->for($this->visit->refresh()),
            'an empty snapshot is a statement, not a gap to fill with live data',
        );
    }

    public function test_a_visit_predating_the_snapshot_falls_back_to_the_site(): void
    {
        $this->visit->forceFill(['required_asset_ids' => null])->save();

        $this->assertCount(1, app(RequiredAssets::class)->for($this->visit->refresh()));
    }

    // --- evidence a technician gave up on ------------------------------------

    public function test_a_discarded_file_cannot_be_uploaded_afterwards(): void
    {
        $media = MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'failed',
        ]);

        $token = $this->deviceToken();

        $this->asToken($token)
            ->postJson("/api/v1/media/{$media->client_media_id}/discard", ['reason' => 'تعذّر الرفع'])
            ->assertOk();

        // A chunk still in flight when the discard landed must not revive it,
        // or the row ends up both complete and discarded and the gate ignores
        // evidence that actually arrived.
        $this->asToken($token)
            ->call('POST', "/api/v1/media/{$media->client_media_id}/chunk", [], [], [],
                [
                    'HTTP_X_UPLOAD_OFFSET' => '0',
                    'CONTENT_TYPE' => 'application/octet-stream',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ], random_bytes(16))
            ->assertStatus(409);

        $this->asToken($token)
            ->postJson("/api/v1/media/{$media->client_media_id}/complete", ['sha256' => str_repeat('a', 64)])
            ->assertStatus(409);

        $this->assertNotSame('complete', $media->refresh()->upload_state);
    }

    // --- the panel cannot mix branches ---------------------------------------

    public function test_an_asset_from_another_branch_cannot_be_attached(): void
    {
        $branchB = Site::create(['client_id' => $this->client->id, 'name' => 'فرع ثانٍ']);
        $assetB = Asset::create([
            'site_id' => $branchB->id,
            'type' => 'split_ac',
            'name' => 'وحدة الفرع الثاني',
            'qr_code' => 'ASSET-BRANCH-B',
        ]);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,   // branch A
                'asset_id' => $assetB->id,      // branch B
                'type' => 'reactive',
                'title' => 'خلط فرعين',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('asset_id');
    }

    public function test_a_contract_that_does_not_cover_the_site_is_refused(): void
    {
        $branchB = Site::create(['client_id' => $this->client->id, 'name' => 'فرع ثالث']);

        // The fixture contract covers branch A only.
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $branchB->id,
                'contract_id' => $this->contract->id,
                'type' => 'preventive',
                'title' => 'عقد لا يغطي الموقع',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('contract_id');
    }

    public function test_a_contract_that_covers_the_site_is_accepted(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,
                'contract_id' => $this->contract->id,
                'type' => 'preventive',
                'title' => 'جولة سليمة',
                'scheduled_start' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHas('ok');
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

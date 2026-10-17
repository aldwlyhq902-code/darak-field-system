<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Device;
use App\Models\Part;
use App\Models\StocktakeSession;
use App\Models\Vehicle;
use App\Models\VehicleStockTransfer;
use App\Services\StocktakeService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * A count from the phone is blind and is a proposal. Before: the phone saw the
 * expected quantity of every part (and its prices) while counting, and its
 * "close" posted every variance — a technician could take N parts, type
 * expected − N, and the shortfall was written off on their word.
 */
class StocktakeReviewTest extends DarakTestCase
{
    private const FORBIDDEN = [
        '"expected_qty"', '"variance_qty"', '"purchase_cost"', '"sale_price"', '"member_price"',
        '"email"', '"phone"', '"hourly_cost"', '"permissions"', '"two_factor',
    ];

    public function test_the_counter_never_sees_the_expected_quantity_or_prices(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 7, $this->vehicleStock->id);
        $this->part->forceFill(['purchase_cost' => 41.5, 'sale_price' => 99.75])->save();
        $session = app(StocktakeService::class)->create($this->vehicleStock->id, null, $this->owner->id);
        $token = $this->techToken();

        $list = $this->asToken($token)->getJson('/api/v1/inventory/stocktakes')->assertOk();
        $this->assertSame($session->id, $list->json('data.0.id'));
        $this->assertSame([], $list->json('data.0.counted'));
        $this->assertClean($list->getContent());

        $scan = $this->asToken($token)
            ->postJson("/api/v1/inventory/stocktakes/{$session->id}/scan", ['code' => $this->part->qr_code, 'qty' => 5])
            ->assertOk();
        $this->assertSame(5.0, (float) $scan->json('data.counted.0.counted_qty'));
        $this->assertClean($scan->getContent());
    }

    public function test_the_phone_submits_and_nothing_moves_until_the_office_approves(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 7, $this->vehicleStock->id);
        $untouched = Part::create([
            'sku' => 'NOT-SCANNED', 'name' => 'Filter', 'unit' => 'pcs', 'is_active' => true,
            'qr_code' => 'PART-NOT-SCANNED', 'purchase_cost' => 10, 'sale_price' => 15,
        ]);
        $this->inventory()->receipt((string) Str::uuid(), $untouched->id, 4, $this->vehicleStock->id);
        $session = app(StocktakeService::class)->create($this->vehicleStock->id, null, $this->owner->id);
        $token = $this->techToken();

        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/scan", ['code' => $this->part->qr_code, 'qty' => 5])->assertOk();
        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', StocktakeSession::STATUS_SUBMITTED);

        $this->assertEqualsWithDelta(7, $this->inventory()->balance($this->part->id, $this->vehicleStock->id), .001, 'no write-off on the phone\'s word');
        $this->asToken($token)->getJson('/api/v1/inventory/stocktakes')->assertJsonCount(0, 'data');

        // The panel shows the variance for review…
        $this->actingAs($this->owner, 'web')->get(route('panel.procurement'))
            ->assertOk()
            ->assertSee('بانتظار اعتماد الفروقات');

        // …and only the approval posts it.
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.procurement.stocktake.complete', $session))
            ->assertSessionHas('ok');

        $this->assertEqualsWithDelta(5, $this->inventory()->balance($this->part->id, $this->vehicleStock->id), .001);
        $this->assertEqualsWithDelta(4, $this->inventory()->balance($untouched->id, $this->vehicleStock->id), .001, 'not scanned is not counted as zero');
        $this->assertSame($this->owner->id, $session->refresh()->approved_by);
    }

    public function test_whoever_counted_cannot_approve(): void
    {
        $service = app(StocktakeService::class);
        $session = $service->create($this->warehouse->id, $this->owner->id, $this->owner->id);
        $service->submit($session, $this->owner->id);

        $this->expectException(BusinessRuleViolation::class);
        $service->complete($session, $this->owner->id);
    }

    public function test_an_assigned_counter_excludes_the_vans_driver(): void
    {
        $session = app(StocktakeService::class)->create($this->vehicleStock->id, $this->otherTechnician->id, $this->owner->id);

        $driver = $this->techToken();
        $this->asToken($driver)->getJson('/api/v1/inventory/stocktakes')->assertJsonCount(0, 'data');
        $this->asToken($driver)->postJson("/api/v1/inventory/stocktakes/{$session->id}/scan", ['code' => $this->part->qr_code, 'qty' => 1])
            ->assertForbidden();
        $this->asToken($driver)->postJson("/api/v1/inventory/stocktakes/{$session->id}/complete")->assertForbidden();

        $counter = $this->tokenFor($this->otherTechnician);
        $this->asToken($counter)->getJson('/api/v1/inventory/stocktakes')->assertJsonPath('data.0.id', $session->id);
    }

    public function test_transfer_lists_carry_names_not_contact_details_or_prices(): void
    {
        $this->part->forceFill(['purchase_cost' => 41.5, 'sale_price' => 99.75])->save();
        $this->otherTechnician->forceFill(['phone' => '0559998887', 'hourly_cost' => 85, 'permissions' => ['operations']])->save();
        $van = Vehicle::create(['plate' => 'TR-9', 'assigned_user_id' => $this->otherTechnician->id, 'operating_branch_id' => $this->operatingBranch->id]);
        $other = $van->stockLocation()->create(['type' => 'vehicle', 'name' => 'Van 9', 'operating_branch_id' => $this->operatingBranch->id, 'is_active' => true]);
        VehicleStockTransfer::create([
            'transfer_no' => 'VTR-ALLOW', 'part_id' => $this->part->id, 'qty' => 2,
            'from_location_id' => $other->id, 'to_location_id' => $this->vehicleStock->id,
            'status' => 'pending_receive', 'requested_by' => $this->owner->id,
        ]);

        $response = $this->asToken($this->techToken())->getJson('/api/v1/inventory/vehicle-transfers')->assertOk();

        $this->assertSame('VTR-ALLOW', $response->json('data.0.transfer_no'));
        $this->assertSame(2.0, (float) $response->json('data.0.qty'));
        $this->assertSame($this->part->name, $response->json('data.0.part.name'));
        $this->assertSame($this->otherTechnician->name, $response->json('data.0.from.technician'));
        $this->assertClean($response->getContent());
        $this->assertStringNotContainsString('0559998887', $response->getContent());
    }

    private function assertClean(string $body): void
    {
        foreach (self::FORBIDDEN as $key) {
            $this->assertStringNotContainsString($key, $body, "response leaks {$key}");
        }
        $this->assertStringNotContainsString('99.75', $body);
    }

    private function techToken(): string
    {
        return $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
    }

    private function tokenFor($user): string
    {
        $device = Device::create(['user_id' => $user->id, 'device_uuid' => (string) Str::uuid(), 'platform' => 'android']);

        return $user->createToken('device:'.$device->device_uuid)->plainTextToken;
    }
}

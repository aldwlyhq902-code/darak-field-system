<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\IdempotencyKey;
use App\Models\Vehicle;
use App\Models\VehicleStockTransfer;
use App\Services\StocktakeService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * A routine business refusal from the phone's field tools (stocktake, van
 * transfers) must reach the technician as a 4xx with a stable code and the
 * Arabic reason. As a 500 the action outbox read it as "server down" and
 * retried forever, the reason never showed, and sign-out stayed blocked.
 */
class FieldActionRefusalTest extends DarakTestCase
{
    public function test_releasing_more_than_the_van_holds_is_a_409_with_the_reason(): void
    {
        $transfer = $this->transfer('pending_release', 5); // the van holds nothing

        $this->asToken($this->senderToken())
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/inventory/vehicle-transfers/{$transfer->id}/release")
            ->assertStatus(409)
            ->assertJsonPath('code', 'SENDER_STOCK_INSUFFICIENT')
            ->assertJsonPath('message', 'الرصيد المتاح في سيارة المرسل لا يكفي للتحويل.');

        $this->assertSame('pending_release', $transfer->refresh()->status);
        // A refusal is not remembered, so a corrected retry under the same key can succeed.
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_accepting_a_transfer_twice_is_a_409_not_a_500(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id);
        $transfer = $this->transfer('pending_receive', 1);
        $token = $this->receiverToken();

        $this->asToken($token)->postJson("/api/v1/inventory/vehicle-transfers/{$transfer->id}/accept")->assertOk();

        $this->asToken($token)->postJson("/api/v1/inventory/vehicle-transfers/{$transfer->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'TRANSFER_ALREADY_PROCESSED');
    }

    public function test_accepting_after_the_sender_ran_short_is_a_409(): void
    {
        $transfer = $this->transfer('pending_receive', 2); // released, then the van was emptied

        $this->asToken($this->receiverToken())->postJson("/api/v1/inventory/vehicle-transfers/{$transfer->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'TRANSFER_STOCK_REFUSED');

        $this->assertSame('pending_receive', $transfer->refresh()->status);
    }

    public function test_stocktake_refusals_carry_codes(): void
    {
        $session = app(StocktakeService::class)->create($this->vehicleStock->id, $this->technician->id, $this->owner->id);
        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/scan", ['code' => 'NO-SUCH-LABEL', 'qty' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCKTAKE_UNKNOWN_CODE');

        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/complete")->assertOk();

        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('code', 'STOCKTAKE_ALREADY_COMPLETED');

        $this->asToken($token)->postJson("/api/v1/inventory/stocktakes/{$session->id}/scan", ['code' => $this->part->qr_code, 'qty' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STOCKTAKE_CLOSED');
    }

    public function test_the_panel_still_shows_the_refusal_as_a_flash_message(): void
    {
        app(StocktakeService::class)->create($this->warehouse->id, null, $this->owner->id);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.procurement.stocktake'), ['stock_location_id' => $this->warehouse->id])
            ->assertRedirect()
            ->assertSessionHas('err', 'يوجد جرد مفتوح لهذا الموقع بالفعل.');
    }

    private function transfer(string $status, float $qty): VehicleStockTransfer
    {
        return VehicleStockTransfer::create([
            'transfer_no' => 'VTR-'.Str::random(5),
            'part_id' => $this->part->id,
            'qty' => $qty,
            'from_location_id' => $this->vehicleStock->id,
            'to_location_id' => $this->receiverLocation()->id,
            'status' => $status,
            'requested_by' => $this->owner->id,
        ]);
    }

    private function receiverLocation()
    {
        $vehicle = Vehicle::firstOrCreate(
            ['plate' => 'RCV-1'],
            ['assigned_user_id' => $this->otherTechnician->id, 'operating_branch_id' => $this->operatingBranch->id],
        );

        return $vehicle->stockLocation()->firstOrCreate(
            ['type' => 'vehicle'],
            ['name' => 'Receiver van', 'operating_branch_id' => $this->operatingBranch->id, 'is_active' => true],
        );
    }

    private function senderToken(): string
    {
        return $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
    }

    private function receiverToken(): string
    {
        $device = Device::create(['user_id' => $this->otherTechnician->id, 'device_uuid' => (string) Str::uuid(), 'platform' => 'android']);

        return $this->otherTechnician->createToken('device:'.$device->device_uuid)->plainTextToken;
    }
}

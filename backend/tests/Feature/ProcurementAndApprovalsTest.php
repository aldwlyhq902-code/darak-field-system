<?php

namespace Tests\Feature;

use App\Models\AdditionalWorkApproval;
use App\Models\ClientPortalUser;
use App\Models\Device;
use App\Models\PurchaseOrder;
use App\Models\StockMove;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleStockTransfer;
use App\Services\CloseGate;
use App\Services\ProcurementService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class ProcurementAndApprovalsTest extends DarakTestCase
{
    public function test_purchase_order_receipt_adds_stock_and_updates_cost(): void
    {
        $supplier = Supplier::create(['name' => 'Parts Co', 'lead_time_days' => 2, 'is_active' => true]);
        $order = PurchaseOrder::create(['po_number' => 'PO-TEST-1', 'supplier_id' => $supplier->id, 'destination_location_id' => $this->warehouse->id, 'ordered_on' => now(), 'status' => 'ordered', 'subtotal' => 400, 'vat_amount' => 60, 'total_amount' => 460, 'created_by' => $this->owner->id]);
        $item = $order->items()->create(['part_id' => $this->part->id, 'qty_ordered' => 10, 'unit_cost' => 40]);
        app(ProcurementService::class)->receivePurchaseItem($item, 4, $this->owner->id);
        $this->assertEqualsWithDelta(4, $this->inventory()->balance($this->part->id, $this->warehouse->id), .001);
        $this->assertSame('partially_received', $order->refresh()->status);
        $this->assertEqualsWithDelta(40, (float) $this->part->refresh()->purchase_cost, .001);
    }

    public function test_reservation_reduces_available_stock_and_other_visit_cannot_consume_it(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $this->vehicleStock->id);
        app(ProcurementService::class)->reserve($this->visit, $this->part->id, $this->vehicleStock->id, 4, $this->owner->id);
        $this->assertEqualsWithDelta(1, $this->inventory()->availableBalance($this->part->id, $this->vehicleStock->id), .001);
        $this->expectException(\RuntimeException::class);
        $this->inventory()->transferVehicleStock((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->warehouse->id);
    }

    public function test_issuing_to_reserved_visit_consumes_its_reservation(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $this->vehicleStock->id);
        app(ProcurementService::class)->reserve($this->visit, $this->part->id, $this->vehicleStock->id, 2, $this->owner->id);
        $this->inventory()->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
        $this->assertSame('consumed', StockReservation::firstOrFail()->status);
    }

    public function test_vehicle_transfer_waits_for_acceptance_before_moving_stock(): void
    {
        $otherVehicle = Vehicle::create(['plate' => 'TR-2', 'assigned_user_id' => $this->otherTechnician->id, 'operating_branch_id' => $this->operatingBranch->id]);
        $otherLocation = $otherVehicle->stockLocation()->create(['type' => 'vehicle', 'name' => 'Vehicle 2', 'operating_branch_id' => $this->operatingBranch->id, 'is_active' => true]);
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $this->vehicleStock->id);
        $transfer = VehicleStockTransfer::create(['transfer_no' => 'VTR-TEST', 'part_id' => $this->part->id, 'qty' => 2, 'from_location_id' => $this->vehicleStock->id, 'to_location_id' => $otherLocation->id, 'status' => 'pending', 'requested_by' => $this->owner->id]);
        $this->assertEqualsWithDelta(0, $this->inventory()->balance($this->part->id, $otherLocation->id), .001);
        app(ProcurementService::class)->acceptTransfer($transfer, $this->otherTechnician->id);
        $this->assertEqualsWithDelta(2, $this->inventory()->balance($this->part->id, $otherLocation->id), .001);
        $this->assertSame(StockMove::VEHICLE_TRANSFER, StockMove::latest('id')->first()->move_type);
    }

    public function test_only_receiving_technician_or_owner_can_accept_vehicle_transfer(): void
    {
        $otherVehicle = Vehicle::create(['plate' => 'TR-3', 'assigned_user_id' => $this->otherTechnician->id, 'operating_branch_id' => $this->operatingBranch->id]);
        $otherLocation = $otherVehicle->stockLocation()->create(['type' => 'vehicle', 'name' => 'Vehicle 3', 'operating_branch_id' => $this->operatingBranch->id, 'is_active' => true]);
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id);
        $transfer = VehicleStockTransfer::create(['transfer_no' => 'VTR-AUTH', 'part_id' => $this->part->id, 'qty' => 1, 'from_location_id' => $this->vehicleStock->id, 'to_location_id' => $otherLocation->id, 'status' => 'pending', 'requested_by' => $this->owner->id]);

        $wrongToken = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
        $this->asToken($wrongToken)->postJson('/api/v1/inventory/vehicle-transfers/'.$transfer->id.'/accept')->assertForbidden();

        $otherDevice = Device::create(['user_id' => $this->otherTechnician->id, 'device_uuid' => (string) Str::uuid(), 'platform' => 'android']);
        $receiverToken = $this->otherTechnician->createToken('device:'.$otherDevice->device_uuid)->plainTextToken;
        $this->asToken($receiverToken)->postJson('/api/v1/inventory/vehicle-transfers/'.$transfer->id.'/accept')->assertOk();
    }

    public function test_client_approves_additional_work_and_pending_request_blocks_close(): void
    {
        $portal = ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Approver', 'email' => 'work@test.local', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true]);
        $approval = AdditionalWorkApproval::create(['public_reference' => (string) Str::uuid(), 'visit_id' => $this->visit->id, 'client_id' => $this->client->id, 'title' => 'Replace compressor', 'description' => 'Compressor has failed', 'amount' => 1000, 'vat_amount' => 150, 'total_amount' => 1150, 'status' => 'pending', 'sent_at' => now(), 'created_by' => $this->owner->id]);
        $codes = collect(app(CloseGate::class)->blockers($this->visit))->pluck('code');
        $this->assertTrue($codes->contains('ADDITIONAL_WORK_PENDING'));
        $this->actingAs($portal, 'client')->post(route('client.additional-work.respond', $approval), ['decision' => 'approved'])->assertRedirect(route('client.home'));
        $this->assertSame('approved', $approval->refresh()->status);
    }

    public function test_procurement_page_renders(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('panel.procurement'))->assertOk()->assertSee('المشتريات والحجوزات');
    }

    public function test_bootstrap_contains_reserved_parts_and_client_approval_status(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id);
        app(ProcurementService::class)->reserve($this->visit, $this->part->id, $this->vehicleStock->id, 1, $this->owner->id);
        AdditionalWorkApproval::create(['public_reference' => (string) Str::uuid(), 'visit_id' => $this->visit->id, 'client_id' => $this->client->id, 'title' => 'Extra', 'description' => 'Extra work pending', 'amount' => 100, 'vat_amount' => 15, 'total_amount' => 115, 'status' => 'pending', 'sent_at' => now(), 'created_by' => $this->owner->id]);

        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
        $response = $this->asToken($token)->getJson('/api/v1/sync/bootstrap')->assertOk();
        $visit = collect($response->json('visits'))->firstWhere('id', $this->visit->id);
        $this->assertSame($this->part->id, $visit['stock_reservations'][0]['part_id']);
        $this->assertSame('pending', $visit['additional_work_approvals'][0]['status']);
    }
}

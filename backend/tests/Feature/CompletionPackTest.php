<?php

namespace Tests\Feature;

use App\Models\AdditionalWorkApproval;
use App\Models\Client;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Visit;
use App\Services\FaultPredictionTrainer;
use App\Services\RfqService;
use App\Services\RoutePlanningService;
use Tests\DarakTestCase;

class CompletionPackTest extends DarakTestCase
{
    public function test_technician_can_create_itemised_additional_work_request_for_own_active_visit(): void
    {
        $technician = $this->technician;
        $visit = $this->visit;
        $visit->forceFill(['state' => Visit::STATE_STARTED])->save();

        $this->actingAs($technician, 'sanctum')->postJson("/api/v1/visits/{$visit->id}/additional-work", [
            'title' => 'استبدال حساس', 'description' => 'تعذر الإصلاح دون القطعة',
            'items' => [['description' => 'حساس ضغط', 'qty' => 2, 'unit_price' => 100]],
        ])->assertCreated()->assertJsonPath('data.total_amount', '230.00');

        $approval = AdditionalWorkApproval::firstOrFail();
        $this->assertSame('technician_app', $approval->requested_from);
        $this->assertSame('حساس ضغط', $approval->items[0]['description']);
    }

    public function test_rfq_compares_supplier_quote_and_awards_to_purchase_order_once(): void
    {
        $owner = $this->owner;
        $warehouse = $this->warehouse;
        $part = $this->part;
        $supplier = Supplier::create(['name' => 'مورد الاختبار', 'lead_time_days' => 2, 'is_active' => true]);
        $service = app(RfqService::class);
        $rfq = $service->create(['destination_location_id' => $warehouse->id, 'response_due_on' => today()->addWeek(), 'items' => [['part_id' => $part->id, 'qty' => 4]]], $owner->id);
        $quote = $service->recordSupplierQuotation($rfq, ['supplier_id' => $supplier->id, 'lead_time_days' => 2, 'items' => [['part_id' => $part->id, 'qty' => 4, 'unit_cost' => 50]]], $owner->id);
        $order = $service->award($rfq, $quote, $owner->id);

        $this->assertSame('awarded', $rfq->refresh()->status);
        $this->assertSame($order->id, $rfq->purchase_order_id);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'part_id' => $part->id, 'qty_ordered' => 4]);
    }

    public function test_panel_user_isolated_to_assigned_operating_branch(): void
    {
        $company = OperatingCompany::create(['name' => 'شركة', 'is_active' => true]);
        $a = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'أ', 'code' => 'A', 'is_active' => true]);
        $b = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'ب', 'code' => 'B', 'is_active' => true]);
        Client::withoutGlobalScopes()->create(['name' => 'عميل أ', 'commercial_name' => 'عميل أ', 'category' => 'other', 'payment_term' => 'monthly', 'operating_company_id' => $company->id, 'operating_branch_id' => $a->id, 'is_active' => true]);
        Client::withoutGlobalScopes()->create(['name' => 'عميل ب', 'commercial_name' => 'عميل ب', 'category' => 'other', 'payment_term' => 'monthly', 'operating_company_id' => $company->id, 'operating_branch_id' => $b->id, 'is_active' => true]);
        StockLocation::withoutGlobalScopes()->create(['name' => 'مستودع أ', 'type' => StockLocation::TYPE_WAREHOUSE, 'operating_branch_id' => $a->id, 'is_active' => true]);
        StockLocation::withoutGlobalScopes()->create(['name' => 'مستودع ب', 'type' => StockLocation::TYPE_WAREHOUSE, 'operating_branch_id' => $b->id, 'is_active' => true]);
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'operating_branch_id' => $a->id]);

        $this->actingAs($user);
        $this->assertSame(['عميل أ'], Client::pluck('name')->all());
        $this->assertSame(['مستودع أ'], StockLocation::whereNotNull('operating_branch_id')->pluck('name')->all());
    }

    public function test_live_route_has_deterministic_fallback_without_credentials(): void
    {
        config(['darak.routing.provider' => 'local']);
        $result = app(RoutePlanningService::class)->estimate(24.7136, 46.6753, 24.7743, 46.7386, now());
        $this->assertSame('local-fallback', $result['provider']);
        $this->assertGreaterThan(0, $result['minutes']);
        $this->assertGreaterThan(0, $result['distance_km']);
    }

    public function test_technician_location_refreshes_client_eta_and_route_metadata(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_EN_ROUTE])->save();

        $this->actingAs($this->technician, 'sanctum')->postJson("/api/v1/visits/{$this->visit->id}/location", [
            'lat' => 21.6000, 'lng' => 39.1800,
        ])->assertOk()->assertJsonPath('data.route_provider', 'local-fallback');

        $this->visit->refresh();
        $this->assertNotNull($this->visit->estimated_arrival_at);
        $this->assertSame('local-fallback', $this->visit->route_provider);
        $this->assertNotNull($this->visit->location_updated_at);
    }

    public function test_machine_learning_training_is_gated_until_enough_timeline_samples_exist(): void
    {
        config(['darak.prediction.minimum_samples' => 500]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن تدريب النموذج قبل توفر 500 عينة زمنية');

        app(FaultPredictionTrainer::class)->train();
    }
}

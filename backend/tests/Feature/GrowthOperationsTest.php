<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\CommissionRule;
use App\Models\FinancialApproval;
use App\Models\InventoryLot;
use App\Models\KnowledgeArticle;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\StocktakeSession;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommercialService;
use App\Services\FinancialApprovalService;
use App\Services\ProcurementService;
use App\Services\ReplenishmentService;
use App\Services\StocktakeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class GrowthOperationsTest extends DarakTestCase
{
    public function test_custom_installments_include_down_payment_and_exact_schedule(): void
    {
        $quote = Quotation::create([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-CUSTOM',
            'client_id' => $this->client->id, 'title' => 'Custom plan', 'package_code' => 'basic',
            'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'custom', 'duration_months' => 12,
            'starts_on' => now()->addDay(), 'valid_until' => now()->addMonth(), 'status' => 'accepted',
            'down_payment_amount' => 200, 'custom_installments' => [
                ['due_on' => now()->addMonth()->toDateString(), 'amount' => 300],
                ['due_on' => now()->addMonths(2)->toDateString(), 'amount' => 500],
            ], 'created_by' => $this->owner->id,
        ]);
        $quote->sites()->attach($this->site);
        $contract = app(CommercialService::class)->convertQuotation($quote, $this->owner->id);

        $this->assertCount(3, $contract->installments);
        $this->assertEquals([200.0, 300.0, 500.0], $contract->installments->map(fn ($row) => (float) $row->amount)->all());
    }

    public function test_replenishment_and_mobile_stocktake_create_audited_adjustment(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);
        $session = app(StocktakeService::class)->create($this->warehouse->id, $this->technician->id, $this->owner->id);
        app(StocktakeService::class)->scan($session, $this->part->qr_code, 3);
        app(StocktakeService::class)->complete($session, $this->technician->id);

        $this->assertEqualsWithDelta(3, $this->inventory()->balance($this->part->id, $this->warehouse->id), .001);
        $this->assertSame('completed', StocktakeSession::firstOrFail()->status);
        $this->part->forceFill(['reorder_level' => 4])->save();
        $this->assertCount(1, app(ReplenishmentService::class)->scan());
        $this->assertDatabaseHas('replenishment_requests', ['part_id' => $this->part->id, 'status' => 'open']);
    }

    public function test_critical_part_receipt_tracks_lot_warranty_and_vehicle_location(): void
    {
        $this->part->forceFill(['critical_tracking' => true, 'default_warranty_months' => 12])->save();
        $supplier = Supplier::create(['name' => 'Tracked Supplier', 'lead_time_days' => 1, 'is_active' => true]);
        $order = PurchaseOrder::create(['po_number' => 'PO-LOT', 'supplier_id' => $supplier->id, 'destination_location_id' => $this->warehouse->id, 'ordered_on' => now(), 'status' => 'ordered', 'subtotal' => 76, 'vat_amount' => 11.4, 'total_amount' => 87.4, 'created_by' => $this->owner->id]);
        $item = $order->items()->create(['part_id' => $this->part->id, 'qty_ordered' => 2, 'unit_cost' => 38]);
        app(ProcurementService::class)->receivePurchaseItem($item, 2, $this->owner->id, ['lot_number' => 'LOT-2026']);
        $this->inventory()->loadVehicle((string) Str::uuid(), $this->part->id, 1, $this->warehouse->id, $this->vehicleStock->id);

        $this->assertDatabaseHas('inventory_lots', ['lot_number' => 'LOT-2026', 'stock_location_id' => $this->vehicleStock->id, 'qty_remaining' => 1]);
        $this->assertNotNull(InventoryLot::where('stock_location_id', $this->warehouse->id)->first()->warranty_until);
    }

    public function test_financial_discount_requires_two_distinct_approvers(): void
    {
        $quote = Quotation::create(['series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-APPROVAL', 'client_id' => $this->client->id, 'title' => 'Approval', 'package_code' => 'basic', 'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'upfront', 'duration_months' => 12, 'starts_on' => now(), 'valid_until' => now()->addMonth(), 'status' => 'draft', 'created_by' => $this->owner->id]);
        $first = User::create(['name' => 'Approver 1', 'email' => 'a1@test.local', 'password' => Hash::make('Secret-123!'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $second = User::create(['name' => 'Approver 2', 'email' => 'a2@test.local', 'password' => Hash::make('Secret-123!'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $approval = FinancialApproval::create(['public_reference' => (string) Str::uuid(), 'action_type' => 'discount', 'subject_type' => 'quotation', 'subject_id' => $quote->id, 'amount' => 100, 'reason' => 'Retention', 'status' => 'pending_first', 'requested_by' => $this->owner->id]);
        app(FinancialApprovalService::class)->approve($approval, $first->id);
        app(FinancialApprovalService::class)->approve($approval, $second->id);

        $this->assertSame('approved', $approval->refresh()->status);
        $this->assertEqualsWithDelta(900, (float) $quote->refresh()->price_amount, .001);
    }

    public function test_panel_area_permissions_are_enforced_for_admin_accounts(): void
    {
        $admin = User::create(['name' => 'Clients Only', 'email' => 'clients@test.local', 'password' => Hash::make('Secret-123!'), 'role' => User::ROLE_ADMIN, 'is_active' => true, 'permissions' => ['clients']]);
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin, 'web')->get(route('panel.clients'))->assertOk()
            ->assertSee('العملاء والعقود')
            ->assertDontSee('الربحية')
            ->assertDontSee('الإدارة وCRM');
        $this->actingAs($admin, 'web')->get(route('panel.finance'))->assertForbidden();
    }

    public function test_technician_receives_knowledge_based_diagnosis_suggestions(): void
    {
        KnowledgeArticle::create(['fault_code' => 'E01', 'asset_type' => $this->asset->type, 'title' => 'Sensor fault', 'diagnosis' => 'Check sensor', 'solution' => 'Replace sensor', 'suggested_part_ids' => [$this->part->id], 'is_published' => true]);
        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        $this->asToken($token)->getJson('/api/v1/visits/'.$this->visit->id.'/diagnosis-suggestions?fault_code=E01')
            ->assertOk()->assertJsonPath('data.0.title', 'Sensor fault')->assertJsonPath('prediction_readiness.ready', false);
    }

    public function test_admin_operations_page_and_commission_generator_are_available(): void
    {
        CommissionRule::create(['name' => 'Visit fixed', 'applies_to_role' => User::ROLE_TECHNICIAN, 'basis' => 'completed_visit', 'rate' => 0, 'fixed_amount' => 25, 'is_active' => true]);
        $this->visit->forceFill(['state' => 'completed', 'closed_at' => now()])->save();

        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $this->assertDatabaseHas('commission_entries', ['user_id' => $this->technician->id, 'commission_amount' => 25]);
        $this->actingAs($this->owner, 'web')->get(route('panel.admin-operations'))->assertOk()->assertSee('CRM');
    }

    public function test_client_delegate_cannot_access_a_visit_outside_assigned_sites(): void
    {
        $otherSite = $this->client->sites()->create(['name' => 'Other Branch']);
        $portal = ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Delegate', 'email' => 'delegate@test.local', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true, 'permissions' => ['reports.dispute']]);
        $portal->allowedSites()->attach($otherSite);

        $this->actingAs($portal, 'client')->get(route('client.visit', $this->visit))->assertForbidden();
    }

    public function test_client_delegate_cannot_view_or_authorise_documents_spanning_restricted_sites(): void
    {
        $otherSite = $this->client->sites()->create(['name' => 'Restricted Site']);
        $portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Limited Delegate',
            'email' => 'limited-delegate@test.local', 'password' => Hash::make('Strong-Client9!'),
            'is_active' => true, 'permissions' => ['quotes.approve', 'contracts.sign'],
        ]);
        $portal->allowedSites()->attach($this->site);
        $quotation = Quotation::create([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-MIXED-SITES',
            'client_id' => $this->client->id, 'title' => 'Mixed sites', 'package_code' => 'basic',
            'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'upfront',
            'duration_months' => 12, 'starts_on' => now(), 'valid_until' => now()->addMonth(),
            'status' => Quotation::STATUS_SENT, 'created_by' => $this->owner->id,
        ]);
        $quotation->sites()->attach([$this->site->id, $otherSite->id]);
        $this->contract->sites()->attach($otherSite);

        $this->actingAs($portal, 'client')->get(route('client.quotation', $quotation))->assertForbidden();
        $this->actingAs($portal, 'client')->post(route('client.quotation.accept', $quotation), ['accept_terms' => '1'])->assertForbidden();
        $this->actingAs($portal, 'client')->get(route('client.contract', $this->contract))->assertForbidden();
        $this->actingAs($portal, 'client')->post(route('client.contract.sign', $this->contract), [
            'signed_name' => 'Limited Delegate', 'accept_terms' => '1',
        ])->assertForbidden();
        $this->actingAs($portal, 'client')->get(route('client.home'))->assertDontSee('QT-MIXED-SITES');
    }

    public function test_audit_hash_chain_is_verifiable(): void
    {
        app(AuditLogger::class)->record('test.first', $this->client, null, ['name' => $this->client->name], $this->owner->id);
        app(AuditLogger::class)->record('test.second', $this->site, null, ['name' => $this->site->name], $this->owner->id);

        $this->artisan('darak:audit-verify')->expectsOutputToContain('Audit chain valid (2 hashed entries)')->assertSuccessful();
    }
}

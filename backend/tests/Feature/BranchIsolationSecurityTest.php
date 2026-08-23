<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Device;
use App\Models\EmergencyReport;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\PurchaseOrder;
use App\Models\Site;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class BranchIsolationSecurityTest extends DarakTestCase
{
    public function test_route_bound_sensitive_children_are_not_accessible_across_branches(): void
    {
        [, $branchB, $admin, $otherVisit] = $this->branchFixture();
        $otherClient = $otherVisit->workOrder()->withoutGlobalScopes()->firstOrFail()->client()->withoutGlobalScopes()->firstOrFail();
        $otherSite = $otherVisit->site()->withoutGlobalScopes()->firstOrFail();
        $otherContract = Contract::withoutGlobalScopes()->create([
            'client_id' => $otherClient->id, 'contract_no' => 'PRIVATE-CONTRACT',
            'package_code' => 'basic', 'price_amount' => 1000, 'vat_rate' => .15,
            'starts_on' => now(), 'status' => 'active',
        ]);
        $installment = ContractInstallment::withoutGlobalScopes()->create([
            'contract_id' => $otherContract->id, 'installment_no' => 1, 'due_on' => now(),
            'amount' => 100, 'vat_amount' => 15, 'total_amount' => 115,
            'paid_amount' => 0, 'status' => 'pending',
        ]);
        $emergency = EmergencyReport::withoutGlobalScopes()->create([
            'public_reference' => (string) Str::uuid(), 'site_id' => $otherSite->id,
            'reporter_name' => 'Private Reporter', 'reporter_phone' => '0501234567',
            'category' => 'ac', 'severity' => 'urgent', 'description' => 'Private branch incident',
            'status' => EmergencyReport::STATUS_NEW, 'source' => 'site_qr',
        ]);
        $location = StockLocation::withoutGlobalScopes()->create([
            'name' => 'B-PRIVATE-WAREHOUSE', 'type' => StockLocation::TYPE_WAREHOUSE,
            'operating_branch_id' => $branchB->id, 'is_active' => true,
        ]);
        $supplier = Supplier::create(['name' => 'Private Supplier', 'is_active' => true]);
        $order = PurchaseOrder::withoutGlobalScopes()->create([
            'po_number' => 'PO-PRIVATE-B', 'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id, 'ordered_on' => now(),
            'status' => 'ordered', 'subtotal' => 100, 'vat_amount' => 15, 'total_amount' => 115,
            'created_by' => $admin->id,
        ]);
        $item = $order->items()->withoutGlobalScopes()->create([
            'part_id' => $this->part->id, 'qty_ordered' => 2, 'qty_received' => 0, 'unit_cost' => 50,
        ]);

        $this->actingAs($admin, 'web')->get(route('panel.emergency', $emergency->id))->assertNotFound();
        $this->actingAs($admin, 'web')->get(route('panel.emergency.photo', $emergency->id))->assertNotFound();
        $this->actingAs($admin, 'web')->post(route('panel.emergency.convert', $emergency->id))->assertNotFound();
        $this->actingAs($admin, 'web')->post(route('panel.emergency.reject', $emergency->id), ['reason' => 'no'])->assertNotFound();
        $this->actingAs($admin, 'web')->post(route('panel.installment.payment', $installment->id), [
            'amount' => 10, 'paid_on' => now()->toDateString(), 'method' => 'cash',
        ])->assertNotFound();
        $this->actingAs($admin, 'web')->post(route('panel.procurement.receive', $item->id), ['qty' => 1])->assertNotFound();

        $this->assertDatabaseHas('contract_installments', ['id' => $installment->id, 'paid_amount' => 0]);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'qty_received' => 0]);
        $this->assertDatabaseHas('emergency_reports', ['id' => $emergency->id, 'status' => EmergencyReport::STATUS_NEW]);
    }

    public function test_company_operator_sees_its_branches_but_not_another_company_and_tenantless_user_fails_closed(): void
    {
        [$branchA, $branchB] = $this->branchFixture();
        $companyId = $branchA->operating_company_id;
        $companyAdmin = $this->panelAdmin(['operating_company_id' => $companyId]);
        $tenantlessAdmin = $this->panelAdmin(['email' => 'tenantless@test.local']);
        $platformAdmin = $this->panelAdmin(['email' => 'platform@test.local', 'is_platform_admin' => true]);

        $sameCompanyClient = Client::withoutGlobalScopes()->create([
            'name' => 'Branch B Visible', 'category' => 'other', 'payment_term' => 'monthly',
            'operating_company_id' => $companyId, 'operating_branch_id' => $branchB->id, 'is_active' => true,
        ]);
        $sameCompanySite = Site::withoutGlobalScopes()->create(['client_id' => $sameCompanyClient->id, 'name' => 'B Site']);
        $sameCompanyReport = $this->emergencyFor($sameCompanySite, 'SAME-COMPANY');

        $otherCompany = OperatingCompany::create(['name' => 'Other Company', 'is_active' => true]);
        $otherBranch = OperatingBranch::create(['operating_company_id' => $otherCompany->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
        $otherClient = Client::withoutGlobalScopes()->create([
            'name' => 'Other Company Private', 'category' => 'other', 'payment_term' => 'monthly',
            'operating_company_id' => $otherCompany->id, 'operating_branch_id' => $otherBranch->id, 'is_active' => true,
        ]);
        $otherSite = Site::withoutGlobalScopes()->create(['client_id' => $otherClient->id, 'name' => 'Other Site']);
        $otherReport = $this->emergencyFor($otherSite, 'OTHER-COMPANY');

        $this->actingAs($companyAdmin, 'web')->get(route('panel.emergency', $sameCompanyReport->id))->assertOk();
        $this->actingAs($companyAdmin, 'web')->get(route('panel.emergency', $otherReport->id))->assertNotFound();
        $this->actingAs($tenantlessAdmin, 'web')->get(route('panel.emergency', $sameCompanyReport->id))->assertNotFound();
        $this->actingAs($platformAdmin, 'web')->get(route('panel.emergency', $otherReport->id))->assertOk();
    }

    public function test_sanctum_admin_can_only_list_and_export_its_operating_branch(): void
    {
        [$branchA, $branchB, $admin] = $this->branchFixture();
        $this->warehouse->forceFill(['operating_branch_id' => $branchA->id, 'name' => 'A-WAREHOUSE'])->save();
        $otherLocation = StockLocation::withoutGlobalScopes()->create([
            'name' => 'B-WAREHOUSE', 'type' => StockLocation::TYPE_WAREHOUSE,
            'operating_branch_id' => $branchB->id, 'is_active' => true,
        ]);
        StockMove::withoutGlobalScopes()->create([
            'move_type' => StockMove::RECEIPT, 'part_id' => $this->part->id, 'qty' => 1,
            'to_location_id' => $this->warehouse->id, 'idempotency_key' => (string) Str::uuid(),
            'server_received_at' => now(),
        ]);
        StockMove::withoutGlobalScopes()->create([
            'move_type' => StockMove::RECEIPT, 'part_id' => $this->part->id, 'qty' => 1,
            'to_location_id' => $otherLocation->id, 'idempotency_key' => (string) Str::uuid(),
            'server_received_at' => now(),
        ]);

        $device = Device::create(['user_id' => $admin->id, 'device_uuid' => (string) Str::uuid(), 'platform' => 'android']);
        $token = $admin->createToken('device:'.$device->device_uuid)->plainTextToken;

        $this->asToken($token)->getJson('/api/v1/visits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->visit->id);

        $visitsCsv = $this->asToken($token)->get('/api/v1/reports/visits.csv?from='.now()->subDay()->toDateString().'&to='.now()->addDays(2)->toDateString());
        $visitsContent = $visitsCsv->streamedContent();
        $this->assertStringContainsString('WO-TEST-1', $visitsContent);
        $this->assertStringNotContainsString('WO-OTHER-BRANCH', $visitsContent);

        $stockCsv = $this->asToken($token)->get('/api/v1/reports/stock-moves.csv?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString());
        $stockContent = $stockCsv->streamedContent();
        $this->assertStringContainsString('A-WAREHOUSE', $stockContent);
        $this->assertStringNotContainsString('B-WAREHOUSE', $stockContent);
    }

    public function test_branch_admin_cannot_bind_or_download_another_branch_subcontractor_order(): void
    {
        [, , $admin, $otherVisit] = $this->branchFixture();
        $partner = Subcontractor::create(['name' => 'External Partner', 'is_active' => true]);
        $order = SubcontractorOrder::withoutGlobalScopes()->create([
            'subcontractor_id' => $partner->id,
            'work_order_id' => $otherVisit->work_order_id,
            'visit_id' => $otherVisit->id,
            'order_no' => 'SUB-OTHER-BRANCH',
            'purchase_cost' => 100,
            'sale_price' => 150,
            'documents' => [['path' => 'subcontractor-docs/private.pdf', 'title' => 'Private']],
        ]);

        $this->actingAs($admin, 'web')
            ->get(route('panel.sub.doc', ['order' => $order->id, 'index' => 0]))
            ->assertNotFound();
    }

    /** @return array{OperatingBranch, OperatingBranch, User, Visit} */
    private function branchFixture(): array
    {
        $company = OperatingCompany::create(['name' => 'Branch Security Co', 'is_active' => true]);
        $branchA = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'Branch A', 'code' => 'SEC-A', 'is_active' => true]);
        $branchB = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'Branch B', 'code' => 'SEC-B', 'is_active' => true]);
        $this->client->forceFill(['operating_company_id' => $company->id, 'operating_branch_id' => $branchA->id])->save();

        $otherClient = Client::withoutGlobalScopes()->create([
            'name' => 'Other Branch Client', 'commercial_name' => 'Other Branch Client',
            'category' => 'other', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branchB->id,
            'is_active' => true,
        ]);
        $otherSite = Site::withoutGlobalScopes()->create(['client_id' => $otherClient->id, 'name' => 'Other Branch Site']);
        $otherWorkOrder = WorkOrder::withoutGlobalScopes()->create([
            'wo_number' => 'WO-OTHER-BRANCH', 'client_id' => $otherClient->id,
            'site_id' => $otherSite->id, 'type' => 'preventive', 'title' => 'Private work',
            'reported_at' => now(), 'status' => 'scheduled',
        ]);
        $otherVisit = Visit::withoutGlobalScopes()->create([
            'work_order_id' => $otherWorkOrder->id, 'site_id' => $otherSite->id,
            'scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2),
            'state' => Visit::STATE_SCHEDULED, 'state_changed_at' => now(),
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN, 'operating_branch_id' => $branchA->id,
            'operating_company_id' => $company->id,
            'permissions' => ['operations', 'inventory'], 'is_active' => true,
            'password' => Hash::make('secret'),
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$branchA, $branchB, $admin, $otherVisit];
    }

    private function panelAdmin(array $attributes): User
    {
        $admin = User::factory()->create($attributes + [
            'role' => User::ROLE_ADMIN, 'permissions' => ['*'], 'is_active' => true,
            'password' => Hash::make('secret'),
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }

    private function emergencyFor(Site $site, string $reference): EmergencyReport
    {
        return EmergencyReport::withoutGlobalScopes()->create([
            'public_reference' => (string) Str::uuid(), 'site_id' => $site->id,
            'reporter_name' => $reference, 'reporter_phone' => '0501234567',
            'category' => 'ac', 'severity' => 'urgent', 'description' => 'Tenant isolation check',
            'status' => EmergencyReport::STATUS_NEW, 'source' => 'site_qr',
        ]);
    }
}

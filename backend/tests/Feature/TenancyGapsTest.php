<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetFaultPrediction;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ExternalDocument;
use App\Models\FaultPredictionModel;
use App\Models\InventoryLot;
use App\Models\KnowledgeArticle;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\PartFailureReport;
use App\Models\Payment;
use App\Models\PerformanceMetricSetting;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\ReplenishmentRequest;
use App\Models\Site;
use App\Models\StockLocation;
use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\AssetIntelligenceService;
use App\Services\CommissionService;
use App\Services\InvoiceService;
use App\Services\Invoicing\ManualInvoiceProvider;
use App\Services\ReplenishmentService;
use App\Services\SubcontractorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\DarakTestCase;

/**
 * The last company-wide records that were still shared across tenants:
 * commission policies, the subcontractor directory, the failure-prediction
 * model and site-less commercial documents shown to restricted delegates.
 */
class TenancyGapsTest extends DarakTestCase
{
    public function test_an_owner_files_commission_rules_for_their_own_company_only(): void
    {
        $foreign = $this->foreignTenant();

        $this->actingAs($this->owner, 'web')->post(route('panel.admin.commission-rule'), [
            'name' => 'Home visit bonus', 'applies_to_role' => User::ROLE_TECHNICIAN, 'basis' => 'completed_visit',
            'rate' => 0, 'fixed_amount' => 15,
            // A company-bound owner cannot file a policy for someone else.
            'operating_company_id' => $foreign['company']->id,
        ])->assertRedirect()->assertSessionHas('ok');

        $rule = CommissionRule::withoutGlobalScopes()->sole();
        $this->assertSame($this->operatingCompany->id, (int) $rule->operating_company_id);

        // The other company's owner neither sees nor uses it.
        $this->actingAs($foreign['owner'], 'web')->get(route('panel.admin-operations'))
            ->assertOk()->assertDontSee('Home visit bonus');
        $this->actingAs($foreign['owner'], 'web')->post(route('panel.admin.commission-entry'), [
            'commission_rule_id' => $rule->id, 'user_id' => $foreign['technician']->id, 'visit_id' => $foreign['visit']->id,
        ])->assertNotFound();
        $this->assertSame(0, CommissionEntry::count());
    }

    public function test_a_commission_rule_only_pays_its_own_company_people_for_its_own_company_records(): void
    {
        $foreign = $this->foreignTenant();
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        $ours = CommissionRule::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'Ours', 'applies_to_role' => User::ROLE_TECHNICIAN,
            'basis' => 'completed_visit', 'rate' => 0, 'fixed_amount' => 10, 'is_active' => true,
        ]);

        $this->artisan('darak:commissions-generate')->assertSuccessful();

        $this->assertTrue(CommissionEntry::where('commission_rule_id', $ours->id)->where('user_id', $this->technician->id)->exists());
        $this->assertFalse(CommissionEntry::where('user_id', $foreign['technician']->id)->exists(), 'another company\'s policy never pays this company');

        $theirs = CommissionRule::create([
            'operating_company_id' => $foreign['company']->id, 'name' => 'Theirs', 'applies_to_role' => User::ROLE_TECHNICIAN,
            'basis' => 'completed_visit', 'rate' => 0, 'fixed_amount' => 99, 'is_active' => true,
        ]);
        $this->artisan('darak:commissions-generate')->assertSuccessful();

        $this->assertTrue(CommissionEntry::where('commission_rule_id', $theirs->id)->where('user_id', $foreign['technician']->id)->exists());
        $this->assertFalse(CommissionEntry::where('commission_rule_id', $theirs->id)->where('user_id', '!=', $foreign['technician']->id)->exists());

        // Manual accrual refuses a rule from another company, whoever asks.
        $this->expectException(RuntimeException::class);
        app(CommissionService::class)->create($theirs, $this->technician, null, $this->visit->fresh(), null);
    }

    public function test_a_company_less_rule_is_a_platform_default_seen_only_by_platform_admins_and_pays_nobody(): void
    {
        CommissionRule::create([
            'name' => 'Legacy global policy', 'applies_to_role' => User::ROLE_TECHNICIAN, 'basis' => 'completed_visit',
            'rate' => 0, 'fixed_amount' => 10, 'is_active' => true,
        ]);
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();

        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $this->assertSame(0, CommissionEntry::count());

        $this->actingAs($this->owner, 'web')->get(route('panel.admin-operations'))
            ->assertOk()->assertDontSee('Legacy global policy');
        $this->actingAs($this->platformAdmin(), 'web')->get(route('panel.admin-operations'))
            ->assertOk()->assertSee('Legacy global policy');
    }

    public function test_the_subcontractor_directory_its_orders_and_documents_stay_inside_the_company(): void
    {
        $foreign = $this->foreignTenant();
        $this->actingAs($this->owner, 'web')->post(route('panel.sub.partner'), [
            'name' => 'Our Hood Partner', 'phone' => '0561111111',
            'operating_company_id' => $foreign['company']->id,
        ])->assertRedirect()->assertSessionHas('ok');
        $partner = Subcontractor::withoutGlobalScopes()->sole();
        $this->assertSame($this->operatingCompany->id, (int) $partner->operating_company_id);

        $order = app(SubcontractorService::class)->assign($partner, $this->visit->workOrder, 600, 1000, $this->visit);
        $order->forceFill(['documents' => [['path' => 'subcontractor-docs/cert.pdf', 'title' => 'Cert', 'issued_by' => 'Partner']]])->save();

        $this->actingAs($foreign['owner'], 'web')->get(route('panel.sub'))
            ->assertOk()->assertDontSee('Our Hood Partner')->assertDontSee('0561111111')->assertDontSee($order->order_no);
        // The other company can neither hire our partner nor read its documents.
        $this->actingAs($foreign['owner'], 'web')->post(route('panel.sub.assign'), [
            'subcontractor_id' => $partner->id, 'work_order_id' => $foreign['workOrder']->id,
            'purchase_cost' => 100, 'sale_price' => 200,
        ])->assertNotFound();
        $this->actingAs($foreign['owner'], 'web')
            ->get(route('panel.sub.doc', ['order' => $order->id, 'index' => 0]))->assertNotFound();
        $this->assertSame(1, SubcontractorOrder::withoutGlobalScopes()->count());

        // With no scope at all (platform admin, service call) a partner still
        // only works the orders of its own company.
        $this->expectException(RuntimeException::class);
        app(SubcontractorService::class)->assign($partner, $foreign['workOrder'], 100, 200);
    }

    public function test_a_company_less_legacy_partner_is_visible_only_to_platform_admins(): void
    {
        Subcontractor::create(['name' => 'Legacy Shared Partner', 'is_active' => true]);

        $this->actingAs($this->owner, 'web')->get(route('panel.sub'))->assertOk()->assertDontSee('Legacy Shared Partner');
        $this->actingAs($this->platformAdmin(), 'web')->get(route('panel.sub'))->assertOk()->assertSee('Legacy Shared Partner');
    }

    public function test_the_failure_model_is_trained_scored_and_shown_per_company(): void
    {
        config(['darak.prediction.minimum_samples' => 4]);
        $foreign = $this->foreignTenant();
        $foreignAsset = Asset::withoutGlobalScopes()->create([
            'site_id' => $foreign['site']->id, 'type' => 'split_ac', 'name' => 'Foreign Chiller', 'qr_code' => 'ASSET-FRN-1',
        ]);
        $this->faultHistory($this->client->id, $this->site->id, $this->asset->id, $this->technician->id, 5, 'OUR');
        // Below the threshold on its own; together they used to clear it.
        $this->faultHistory($foreign['client']->id, $foreign['site']->id, $foreignAsset->id, $foreign['technician']->id, 3, 'FRN');

        $this->artisan('darak:fault-model-train')->assertSuccessful();

        $ours = FaultPredictionModel::withoutGlobalScopes()->sole();
        $this->assertSame($this->operatingCompany->id, (int) $ours->operating_company_id);
        $this->assertSame(5, $ours->training_samples + $ours->validation_samples, 'trained on this company\'s history only');
        $this->assertSame([$this->asset->id], AssetFaultPrediction::where('fault_prediction_model_id', $ours->id)->pluck('asset_id')->all());

        // Once the other company has enough history it gets its own model; ours stays active.
        $this->faultHistory($foreign['client']->id, $foreign['site']->id, $foreignAsset->id, $foreign['technician']->id, 2, 'FRN2');
        $this->actingAs($foreign['owner'], 'web')->post(route('panel.intelligence.train'))->assertSessionHas('ok');

        $theirs = FaultPredictionModel::withoutGlobalScopes()->where('operating_company_id', $foreign['company']->id)->sole();
        $this->assertSame(5, $theirs->training_samples + $theirs->validation_samples);
        $this->assertTrue($ours->fresh()->is_active);
        $this->assertSame([$foreignAsset->id], AssetFaultPrediction::where('fault_prediction_model_id', $theirs->id)->pluck('asset_id')->all());

        $this->actingAs($foreign['owner'], 'web')->get(route('panel.intelligence'))
            ->assertOk()->assertSee('Foreign Chiller')->assertDontSee('Dining Split Unit')
            ->assertViewHas('trainedModel', fn ($model) => $model->is($theirs));
        $this->actingAs($this->owner, 'web')->get(route('panel.intelligence'))
            ->assertOk()->assertDontSee('Foreign Chiller')
            ->assertViewHas('trainedModel', fn ($model) => $model->is($ours));
    }

    public function test_a_site_restricted_delegate_never_sees_site_less_quotations_or_contracts(): void
    {
        $quotation = Quotation::create([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-NO-SITES',
            'client_id' => $this->client->id, 'title' => 'Legacy without sites', 'package_code' => 'basic',
            'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'upfront',
            'duration_months' => 12, 'starts_on' => now(), 'valid_until' => now()->addMonth(),
            'status' => Quotation::STATUS_SENT, 'created_by' => $this->owner->id,
        ]);
        $contract = Contract::create([
            'client_id' => $this->client->id, 'contract_no' => 'DK-NO-SITES', 'package_code' => 'basic',
            'price_amount' => 900, 'vat_rate' => .15, 'starts_on' => now()->toDateString(),
            'service_window_start' => '07:00:00', 'service_window_end' => '23:00:00', 'sla_minutes' => 240, 'status' => 'active',
        ]);
        $installment = ContractInstallment::create([
            'contract_id' => $contract->id, 'installment_no' => 1, 'due_on' => now()->toDateString(),
            'amount' => 900, 'vat_amount' => 135, 'total_amount' => 1035, 'paid_amount' => 100, 'status' => 'partial',
        ]);
        $payment = Payment::create([
            'receipt_no' => 'RC-NO-SITES', 'contract_installment_id' => $installment->id, 'client_id' => $this->client->id,
            'amount' => 100, 'paid_on' => now()->toDateString(), 'method' => 'cash', 'recorded_by' => $this->owner->id,
        ]);
        $restricted = $this->delegate('restricted@test.local');
        $restricted->allowedSites()->attach($this->site);

        $this->actingAs($restricted, 'client')->get(route('client.quotation', $quotation))->assertForbidden();
        $this->actingAs($restricted, 'client')->post(route('client.quotation.accept', $quotation), ['accept_terms' => '1'])->assertForbidden();
        $this->actingAs($restricted, 'client')->get(route('client.contract', $contract))->assertForbidden();
        $this->actingAs($restricted, 'client')->post(route('client.contract.sign', $contract), [
            'signed_name' => 'Restricted', 'accept_terms' => '1',
        ])->assertForbidden();
        $this->actingAs($restricted, 'client')->get(route('client.payment.receipt', $payment))->assertForbidden();
        $this->actingAs($restricted, 'client')->get(route('client.home'))->assertOk()
            ->assertSee($this->contract->contract_no)
            ->assertDontSee('QT-NO-SITES')->assertDontSee('DK-NO-SITES')->assertDontSee('RC-NO-SITES');
        $this->assertNull($quotation->fresh()->accepted_at);
        $this->assertNull($contract->fresh()->signed_at);

        // An unrestricted delegate keeps full visibility.
        $unrestricted = $this->delegate('unrestricted@test.local');
        $this->actingAs($unrestricted, 'client')->get(route('client.quotation', $quotation))->assertOk();
        $this->actingAs($unrestricted, 'client')->get(route('client.contract', $contract))->assertOk();
        $this->actingAs($unrestricted, 'client')->get(route('client.home'))->assertOk()
            ->assertSee('QT-NO-SITES')->assertSee('DK-NO-SITES')->assertSee('RC-NO-SITES');
    }

    public function test_knowledge_base_articles_stay_inside_the_authoring_company(): void
    {
        $foreign = $this->foreignTenant();
        $foreignAsset = Asset::withoutGlobalScopes()->create([
            'site_id' => $foreign['site']->id, 'type' => $this->asset->type, 'name' => 'Foreign Split', 'qr_code' => 'ASSET-FRN-KB',
        ]);
        $this->actingAs($this->owner, 'web')->post(route('panel.intelligence.article'), [
            'fault_code' => 'E01', 'asset_type' => $this->asset->type, 'title' => 'Our compressor recipe',
            'diagnosis' => 'Our private diagnosis', 'solution' => 'Our private fix',
        ])->assertRedirect()->assertSessionHas('ok');
        $article = KnowledgeArticle::withoutGlobalScopes()->sole();
        $this->assertSame($this->operatingCompany->id, (int) $article->operating_company_id);

        // Our technician gets it; the other company's technician and panel do not.
        $this->actingAs($this->technician, 'web');
        $this->assertContains('Our compressor recipe', app(AssetIntelligenceService::class)->diagnosisSuggestions($this->asset, 'E01')->pluck('title')->all());
        $this->actingAs($foreign['technician'], 'web');
        $this->assertNotContains('Our compressor recipe', app(AssetIntelligenceService::class)->diagnosisSuggestions($foreignAsset, 'E01')->pluck('title')->all());
        $this->actingAs($foreign['owner'], 'web')->get(route('panel.intelligence'))
            ->assertOk()->assertDontSee('Our compressor recipe');
    }

    public function test_the_supplier_directory_and_negotiated_prices_stay_inside_the_company(): void
    {
        $foreign = $this->foreignTenant();
        $foreignWarehouse = StockLocation::withoutGlobalScopes()->create([
            'type' => StockLocation::TYPE_WAREHOUSE, 'name' => 'Foreign Warehouse',
            'operating_branch_id' => $foreign['branch']->id, 'is_active' => true,
        ]);
        $this->actingAs($this->owner, 'web')->post(route('panel.procurement.supplier'), [
            'name' => 'Our Parts Wholesaler', 'vat_number' => '300000000000003', 'lead_time_days' => 2,
        ])->assertSessionHas('ok');
        $supplier = Supplier::withoutGlobalScopes()->sole();
        $this->assertSame($this->operatingCompany->id, (int) $supplier->operating_company_id);
        $this->post(route('panel.procurement.supplier-part', $supplier), ['part_id' => $this->part->id, 'last_price' => 33.5])
            ->assertSessionHas('ok');

        // The nightly scan (no user) suggests a supplier only to its own company's warehouses.
        $this->app['auth']->forgetGuards();
        app(ReplenishmentService::class)->scan();
        $this->assertSame($supplier->id, ReplenishmentRequest::withoutGlobalScopes()->where('stock_location_id', $this->warehouse->id)->value('suggested_supplier_id'));
        $this->assertNull(ReplenishmentRequest::withoutGlobalScopes()->where('stock_location_id', $foreignWarehouse->id)->value('suggested_supplier_id'));

        $this->actingAs($foreign['owner'], 'web')->get(route('panel.procurement'))
            ->assertOk()->assertDontSee('Our Parts Wholesaler')->assertDontSee('300000000000003');
        $this->post(route('panel.procurement.supplier-part', $supplier), ['part_id' => $this->part->id, 'last_price' => 1])
            ->assertNotFound();
        $this->post(route('panel.procurement.order'), [
            'supplier_id' => $supplier->id, 'destination_location_id' => $foreignWarehouse->id,
            'part_id' => $this->part->id, 'qty' => 1, 'unit_cost' => 1,
        ])->assertNotFound();
        $this->assertEqualsWithDelta(33.5, (float) $supplier->parts()->sole()->pivot->last_price, .001);
        $this->assertSame(0, PurchaseOrder::withoutGlobalScopes()->count());
    }

    public function test_part_failure_reports_and_supplier_quality_stay_inside_the_company(): void
    {
        $foreign = $this->foreignTenant();
        $foreignAsset = Asset::withoutGlobalScopes()->create([
            'site_id' => $foreign['site']->id, 'type' => 'split_ac', 'name' => 'Foreign Split', 'qr_code' => 'ASSET-FRN-PF',
        ]);
        $supplier = Supplier::create(['operating_company_id' => $this->operatingCompany->id, 'name' => 'Our Failing Supplier', 'is_active' => true]);
        InventoryLot::create([
            'part_id' => $this->part->id, 'supplier_id' => $supplier->id, 'stock_location_id' => $this->warehouse->id,
            'lot_number' => 'LOT-FAIL', 'qty_received' => 5, 'qty_remaining' => 5, 'unit_cost' => 38,
        ]);
        $this->actingAs($this->owner, 'web')->post(route('panel.intelligence.failure'), [
            'part_id' => $this->part->id, 'lot_ref' => 'LOT-FAIL', 'asset_id' => $this->asset->id, 'failure_kind' => 'burnt',
        ])->assertSessionHas('ok');
        $this->assertSame($this->operatingCompany->id, (int) PartFailureReport::withoutGlobalScopes()->sole()->operating_company_id);
        $this->get(route('panel.intelligence'))->assertOk()->assertSee('Our Failing Supplier');

        $this->actingAs($foreign['owner'], 'web')->get(route('panel.intelligence'))
            ->assertOk()->assertDontSee('Our Failing Supplier');
        // Another company's asset cannot be named by id either.
        $this->post(route('panel.intelligence.failure'), [
            'part_id' => $this->part->id, 'asset_id' => $this->asset->id, 'failure_kind' => 'burnt',
        ])->assertNotFound();
        $this->assertSame(1, PartFailureReport::withoutGlobalScopes()->count());
        $this->assertNotNull($foreignAsset);
    }

    public function test_performance_weights_and_targets_are_set_per_company(): void
    {
        $foreign = $this->foreignTenant();
        $payload = PerformanceMetricSetting::where('category', 'technicians')->get()
            ->mapWithKeys(fn (PerformanceMetricSetting $setting) => [$setting->id => [
                'weight' => (float) $setting->weight,
                'target' => $setting->metric_key === 'sla_rate' ? 97 : (float) $setting->target,
                'minimum_sample' => $setting->minimum_sample,
            ]])->all();

        $this->actingAs($this->owner, 'web')->put(route('panel.performance.settings'), [
            'category' => 'technicians', 'settings' => $payload,
        ])->assertSessionHas('ok');

        $slaTarget = fn (float $expected) => fn ($settings) => (float) $settings['technicians']->firstWhere('metric_key', 'sla_rate')->target === $expected;
        $this->get(route('panel.performance'))->assertOk()->assertViewHas('settings', $slaTarget(97.0));
        // The other company keeps the platform default and cannot touch ours.
        $this->actingAs($foreign['owner'], 'web')->get(route('panel.performance'))->assertOk()->assertViewHas('settings', $slaTarget(95.0));
        $ours = PerformanceMetricSetting::where('operating_company_id', $this->operatingCompany->id)->where('metric_key', 'sla_rate')->sole();
        $this->put(route('panel.performance.settings'), [
            'category' => 'technicians', 'settings' => [$ours->id => ['weight' => 100, 'target' => 1, 'minimum_sample' => 1]],
        ])->assertStatus(422);
        $this->assertEqualsWithDelta(97, (float) $ours->fresh()->target, .001);
    }

    public function test_invoice_and_employee_numbers_are_unique_per_company_not_across_companies(): void
    {
        $foreign = $this->foreignTenant();
        $issued = fn (int $clientId, string $number, string $status) => ExternalDocument::create([
            'doc_type' => ExternalDocument::TYPE_INVOICE, 'provider' => 'manual', 'client_id' => $clientId,
            'external_number' => $number, 'amount' => 100, 'vat_amount' => 15, 'status' => $status,
        ]);
        $issued($foreign['client']->id, 'INV-0001', 'issued');
        $ours = $issued($this->client->id, '', ManualInvoiceProvider::STATUS_PENDING);
        $ours->forceFill(['external_number' => null])->save();

        // Another company's numbering is neither revealed nor a blocker.
        app(InvoiceService::class)->recordManualIssue($ours, ['external_number' => 'INV-0001', 'issued_on' => today()->toDateString()], $this->owner);
        $this->assertSame('issued', $ours->fresh()->status);
        $second = $issued($this->client->id, '', ManualInvoiceProvider::STATUS_PENDING);
        try {
            app(InvoiceService::class)->recordManualIssue($second, ['external_number' => 'INV-0001', 'issued_on' => today()->toDateString()], $this->owner);
            $this->fail('a duplicate inside the company must still be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('مسجل مسبقًا', $e->getMessage());
        }

        $profile = ['employee_no' => 'EMP-100', 'annual_leave_days' => 21];
        $this->actingAs($this->owner, 'web')->put(route('panel.hr.profile', $this->technician), $profile)->assertSessionHas('ok');
        $this->put(route('panel.hr.profile', $this->otherTechnician), $profile)->assertSessionHasErrors('employee_no');
        $this->actingAs($foreign['owner'], 'web')->put(route('panel.hr.profile', $foreign['technician']), $profile)
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
    }

    private function delegate(string $email): ClientPortalUser
    {
        return ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Delegate '.$email, 'email' => $email,
            'password' => Hash::make('Strong-Client9!'), 'is_active' => true,
            'permissions' => ['quotes.approve', 'contracts.sign'],
        ]);
    }

    private function faultHistory(int $clientId, int $siteId, int $assetId, int $technicianId, int $visits, string $prefix): void
    {
        $workOrder = WorkOrder::withoutGlobalScopes()->create([
            'wo_number' => 'WO-'.$prefix.'-FAULT', 'client_id' => $clientId, 'site_id' => $siteId, 'asset_id' => $assetId,
            'type' => 'corrective', 'title' => 'Recurring fault', 'fault_code' => 'E01',
            'reported_at' => CarbonImmutable::now()->subYear(), 'sla_minutes_budget' => 240,
            'sla_due_at' => CarbonImmutable::now()->subYear()->addDay(), 'status' => 'completed',
        ]);
        for ($index = 0; $index < $visits; $index++) {
            $start = CarbonImmutable::now()->subDays(300 - $index * 40);
            Visit::withoutGlobalScopes()->create([
                'work_order_id' => $workOrder->id, 'site_id' => $siteId, 'assigned_user_id' => $technicianId,
                'scheduled_start' => $start, 'scheduled_end' => $start->addHour(), 'state' => Visit::STATE_COMPLETED,
                'state_changed_at' => $start->addHour(), 'closed_at' => $start->addHour(),
            ]);
        }
    }

    private function platformAdmin(): User
    {
        $admin = User::create([
            'name' => 'Platform Admin', 'email' => 'platform-admin@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER, 'is_active' => true,
        ]);
        $admin->forceFill([
            'is_platform_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }

    /** @return array<string, mixed> */
    private function foreignTenant(): array
    {
        $company = OperatingCompany::create(['name' => 'Foreign Co', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create([
            'operating_company_id' => $company->id, 'name' => 'Foreign Branch', 'code' => 'FRN', 'is_active' => true,
        ]);
        $owner = User::create([
            'name' => 'Foreign Owner', 'email' => 'foreign-owner@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER, 'is_active' => true, 'operating_company_id' => $company->id,
        ]);
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $technician = User::create([
            'name' => 'Foreign Technician', 'email' => 'foreign-tech@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_TECHNICIAN, 'is_active' => true,
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id,
        ]);
        $client = Client::withoutGlobalScopes()->create([
            'name' => 'Foreign Client', 'category' => 'restaurant', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $site = Site::withoutGlobalScopes()->create(['client_id' => $client->id, 'name' => 'Foreign Site']);
        $workOrder = WorkOrder::withoutGlobalScopes()->create([
            'wo_number' => 'WO-FRN-1', 'client_id' => $client->id, 'site_id' => $site->id, 'type' => 'corrective',
            'title' => 'Foreign repair', 'reported_at' => CarbonImmutable::now()->subDay(), 'sla_minutes_budget' => 240,
            'sla_due_at' => CarbonImmutable::now()->addDay(), 'status' => 'completed',
        ]);
        $visit = Visit::withoutGlobalScopes()->create([
            'work_order_id' => $workOrder->id, 'site_id' => $site->id, 'assigned_user_id' => $technician->id,
            'scheduled_start' => CarbonImmutable::now()->subHours(3), 'scheduled_end' => CarbonImmutable::now()->subHour(),
            'state' => Visit::STATE_COMPLETED, 'state_changed_at' => CarbonImmutable::now(), 'closed_at' => CarbonImmutable::now(),
        ]);

        return compact('company', 'branch', 'owner', 'technician', 'client', 'site', 'workOrder', 'visit');
    }
}

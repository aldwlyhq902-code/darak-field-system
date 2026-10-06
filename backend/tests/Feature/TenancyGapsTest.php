<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Site;
use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\CommissionService;
use App\Services\SubcontractorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
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

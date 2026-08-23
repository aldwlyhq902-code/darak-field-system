<?php

namespace Tests\Feature;

use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\PerformanceMetricSetting;
use App\Models\Quotation;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedback;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

class PerformanceDashboardTest extends DarakTestCase
{
    public function test_dashboard_ranks_all_categories_and_explains_sample_confidence(): void
    {
        [$branch, $marketer] = $this->performanceFixture();

        $response = $this->actingAs($this->owner, 'web')->get(route('panel.performance', [
            'period' => 'month', 'reference' => now()->toDateString(),
        ]));

        $response->assertOk()
            ->assertSee('مركز الأداء والترتيب')
            ->assertSee($this->technician->name)
            ->assertSee($branch->name)
            ->assertSee($marketer->name)
            ->assertSee('بيانات أولية');
    }

    public function test_owner_can_update_balanced_weights_and_every_change_is_audited(): void
    {
        $settings = PerformanceMetricSetting::where('category', 'technicians')->get();
        $payload = $settings->mapWithKeys(fn (PerformanceMetricSetting $setting) => [$setting->id => [
            'weight' => (float) $setting->weight,
            'target' => $setting->metric_key === 'sla_rate' ? 97 : (float) $setting->target,
            'minimum_sample' => $setting->minimum_sample,
        ]])->all();

        $this->actingAs($this->owner, 'web')->put(route('panel.performance.settings'), [
            'category' => 'technicians', 'settings' => $payload,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseHas('performance_metric_settings', ['category' => 'technicians', 'metric_key' => 'sla_rate', 'target' => 97]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner->id, 'action' => 'performance.settings_updated']);
    }

    public function test_performance_exports_are_available_and_permission_is_enforced(): void
    {
        $this->performanceFixture();
        $query = ['period' => 'month', 'reference' => now()->toDateString()];
        $this->actingAs($this->owner, 'web')->get(route('panel.performance.csv', $query))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('panel.performance.pdf', $query))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $restricted = User::create([
            'name' => 'Restricted', 'email' => 'restricted-performance@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_ADMIN,
            'permissions' => ['clients'], 'is_active' => true,
        ]);
        $restricted->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->actingAs($restricted, 'web')->get(route('panel.performance'))->assertForbidden();
    }

    /** @return array{0: OperatingBranch, 1: User} */
    private function performanceFixture(): array
    {
        $company = OperatingCompany::create(['name' => 'شركة قياس الأداء', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'فرع الأداء', 'code' => 'KPI', 'is_active' => true]);
        $this->technician->forceFill(['operating_branch_id' => $branch->id, 'hourly_cost' => 50])->save();
        $this->client->forceFill(['operating_company_id' => $company->id, 'operating_branch_id' => $branch->id])->save();
        $this->visit->forceFill([
            'scheduled_start' => now()->subDay()->startOfHour(), 'scheduled_end' => now()->subDay()->addHours(2),
            'state' => Visit::STATE_COMPLETED, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(),
            'closed_at' => now()->subDay()->addHour(), 'on_site_seconds' => 3600, 'is_rework' => false,
        ])->save();
        $this->visit->workOrder->forceFill([
            'sla_due_at' => now()->subDay()->addHours(3), 'resolution_summary' => 'تم الإصلاح والاختبار بنجاح',
        ])->save();
        VisitFeedback::create([
            'visit_id' => $this->visit->id, 'rating' => 5, 'resolution_confirmed' => true,
            'is_complaint' => false, 'status' => 'reviewed',
        ]);

        $marketer = User::create([
            'name' => 'مسوق الأداء', 'email' => 'marketer-performance@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_ADMIN,
            'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $lead = SalesLead::create([
            'lead_no' => 'LEAD-KPI-1', 'company_name' => 'عميل نمو', 'stage' => 'won',
            'estimated_value' => 50000, 'next_action_on' => now()->addDay(), 'owner_user_id' => $marketer->id,
        ]);
        SalesActivity::create(['sales_lead_id' => $lead->id, 'type' => 'call', 'note' => 'متابعة ناجحة', 'occurred_at' => now(), 'user_id' => $marketer->id]);
        Quotation::create([
            'series_uuid' => fake()->uuid(), 'version' => 1, 'quote_no' => 'Q-KPI-1',
            'client_id' => $this->client->id, 'title' => 'عقد صيانة', 'package_code' => 'basic',
            'price_amount' => 50000, 'vat_rate' => .15, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'starts_on' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
            'status' => Quotation::STATUS_ACCEPTED, 'created_by' => $marketer->id,
        ]);

        return [$branch, $marketer];
    }
}

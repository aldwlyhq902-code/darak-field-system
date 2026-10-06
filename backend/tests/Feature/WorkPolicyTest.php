<?php

namespace Tests\Feature;

use App\Models\OperatingCompany;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkPolicy;
use App\Services\WorkPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\DarakTestCase;

class WorkPolicyTest extends DarakTestCase
{
    public function test_without_a_policy_nothing_is_checked(): void
    {
        $violations = app(WorkPolicyService::class)->violations(
            $this->technician, CarbonImmutable::parse('2026-11-10 00:30'), CarbonImmutable::parse('2026-11-10 23:30'),
        );

        $this->assertSame([], $violations, 'no hard-coded legal numbers: no policy, no violations');
    }

    public function test_shift_daily_rest_and_weekly_limits_come_only_from_the_active_version(): void
    {
        $this->policy(['max_shift_minutes' => 480, 'max_daily_minutes' => 480, 'min_rest_between_shifts_minutes' => 600, 'max_weekly_minutes' => 1200]);
        $this->assignedVisit('2026-11-10 07:00', '2026-11-10 11:00');
        $service = app(WorkPolicyService::class);

        $sameDay = $service->violations($this->technician, CarbonImmutable::parse('2026-11-10 12:00'), CarbonImmutable::parse('2026-11-10 16:30'));
        $this->assertCount(2, $sameDay);
        $this->assertStringContainsString('مجموع العمل يوم 2026-11-10 سيبلغ 510 دقيقة', $sameDay[0]);
        $this->assertStringContainsString('مدة الوردية يوم 2026-11-10 ستبلغ 570 دقيقة', $sameDay[1]);
        $this->assertStringContainsString('الإصدار 1', $sameDay[1]);

        $this->assertSame([], $service->violations($this->technician, CarbonImmutable::parse('2026-11-10 11:00'), CarbonImmutable::parse('2026-11-10 13:00')));

        $this->assignedVisit('2026-11-11 20:00', '2026-11-11 23:00');
        $rest = $service->violations($this->technician, CarbonImmutable::parse('2026-11-12 07:00'), CarbonImmutable::parse('2026-11-12 09:00'));
        $this->assertCount(1, $rest);
        $this->assertStringContainsString('الراحة بين وردية 2026-11-11 ووردية 2026-11-12 ستكون 480 دقيقة', $rest[0]);

        // 900 minutes already scheduled that week; a 7h day pushes 7 consecutive days past 1200.
        $this->assignedVisit('2026-11-13 08:00', '2026-11-13 12:00');
        $this->assignedVisit('2026-11-14 08:00', '2026-11-14 12:00');
        $weekly = $service->violations($this->technician, CarbonImmutable::parse('2026-11-15 08:00'), CarbonImmutable::parse('2026-11-15 15:00'));
        $this->assertCount(1, $weekly);
        $this->assertStringContainsString('خلال 7 أيام متتالية', $weekly[0]);
        $this->assertStringContainsString('1320', $weekly[0]);
    }

    public function test_a_future_version_or_another_company_policy_does_not_apply(): void
    {
        $this->policy(['max_daily_minutes' => 60], '2026-12-01');
        $other = OperatingCompany::create(['name' => 'Other', 'currency' => 'SAR', 'is_active' => true]);
        WorkPolicy::create([
            'operating_company_id' => $other->id, 'version' => 1, 'effective_from' => '2026-01-01',
            'source' => 'x', 'approved_by' => 'x', 'approved_at' => '2026-01-01', 'rules' => ['max_daily_minutes' => 30],
            'created_by' => $this->owner->id,
        ]);
        $service = app(WorkPolicyService::class);

        $this->assertSame([], $service->violations($this->technician, CarbonImmutable::parse('2026-11-20 08:00'), CarbonImmutable::parse('2026-11-20 12:00')));
        $this->assertCount(1, $service->violations($this->technician, CarbonImmutable::parse('2026-12-02 08:00'), CarbonImmutable::parse('2026-12-02 12:00')));
    }

    public function test_owner_adds_immutable_audited_versions_and_others_cannot(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('panel.hr.work-policies'))->assertOk()->assertSee('لا توجد سياسة سارية');

        $payload = [
            'effective_from' => today()->toDateString(), 'source' => 'مذكرة المستشار رقم 12',
            'approved_by' => 'المستشار القانوني', 'approved_at' => today()->toDateString(),
            'rules' => ['max_daily_minutes' => '480', 'max_shift_minutes' => ''],
        ];
        $this->post(route('panel.hr.work-policies.store'), $payload)->assertRedirect()->assertSessionHas('ok');
        $this->post(route('panel.hr.work-policies.store'), ['rules' => ['max_daily_minutes' => '420']] + $payload)->assertRedirect();

        $versions = WorkPolicy::orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame(['max_daily_minutes' => 480], $versions[0]->rules);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_policy.version_created', 'auditable_id' => $versions[1]->id]);
        $this->post(route('panel.hr.work-policies.store'), ['rules' => []] + $payload)->assertSessionHasErrors('rules');

        try {
            $versions[0]->forceFill(['rules' => ['max_daily_minutes' => 999]])->save();
            $this->fail('a published version must not be editable');
        } catch (LogicException) {
            $this->assertSame(['max_daily_minutes' => 480], $versions[0]->fresh()->rules);
        }
        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(QueryException::class);
            DB::transaction(fn () => DB::table('work_policies')->where('id', $versions[0]->id)->update(['source' => 'tampered']));
        }

        $hrAdmin = User::create([
            'name' => 'HR', 'email' => 'hr@test.local', 'password' => Hash::make('Strong-Hr9!'), 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'operating_company_id' => $this->operatingCompany->id, 'permissions' => ['hr'],
        ]);
        $hrAdmin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($hrAdmin, 'web')->post(route('panel.hr.work-policies.store'), $payload)->assertForbidden();
    }

    /** @param array<string, int> $rules */
    private function policy(array $rules, string $effectiveFrom = '2026-11-01'): WorkPolicy
    {
        return WorkPolicy::create([
            'operating_company_id' => $this->operatingCompany->id, 'version' => WorkPolicy::max('version') + 1,
            'effective_from' => $effectiveFrom, 'source' => 'Specialist memo', 'approved_by' => 'Specialist',
            'approved_at' => '2026-10-01', 'rules' => $rules, 'created_by' => $this->owner->id,
        ]);
    }

    private function assignedVisit(string $start, string $end): Visit
    {
        return Visit::create([
            'work_order_id' => WorkOrder::firstOrFail()->id, 'site_id' => $this->site->id,
            'assigned_user_id' => $this->technician->id,
            'scheduled_start' => CarbonImmutable::parse($start), 'scheduled_end' => CarbonImmutable::parse($end),
            'state' => Visit::STATE_SCHEDULED, 'state_changed_at' => now(),
        ]);
    }
}

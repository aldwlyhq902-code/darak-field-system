<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedback;
use App\Services\PerformanceScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class PerformanceScoreTenancyTest extends DarakTestCase
{
    public function test_rankings_never_include_another_company_people_or_branches(): void
    {
        $company = OperatingCompany::create(['name' => 'Rival Co', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'Rival Branch', 'code' => 'RIV', 'is_active' => true]);
        $client = Client::withoutGlobalScopes()->create([
            'name' => 'Rival Client', 'category' => 'other', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        foreach ([User::ROLE_TECHNICIAN => 'Rival Tech', User::ROLE_OWNER => 'Rival Owner', User::ROLE_ADMIN => 'Rival Seller'] as $role => $name) {
            $user = $this->user($name, $role, $company->id, $branch->id);
        }
        Quotation::withoutGlobalScopes()->create([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'Q-RIVAL', 'client_id' => $client->id,
            'title' => 'Rival', 'package_code' => 'basic', 'price_amount' => 100, 'vat_rate' => .15, 'billing_cycle' => 'upfront',
            'duration_months' => 12, 'starts_on' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
            'status' => 'accepted', 'created_by' => $user->id,
        ]);
        // A legacy row with a branch of ours but no company column still belongs to us.
        $legacy = $this->user('Legacy Tech', User::ROLE_TECHNICIAN, null, $this->operatingBranch->id);

        $this->actingAs($this->owner, 'web');
        $dashboard = app(PerformanceScoreService::class)->dashboard($this->owner, now()->startOfMonth()->toImmutable(), now()->endOfMonth()->toImmutable());
        $names = collect($dashboard['categories'])->flatten(1)->pluck('name');

        $this->assertFalse($names->contains('Rival Tech'));
        $this->assertFalse($names->contains('Rival Owner'));
        $this->assertFalse($names->contains('Rival Seller'));
        $this->assertFalse($names->contains('Rival Branch'));
        $this->assertTrue($names->contains($this->technician->name));
        $this->assertTrue($names->contains($legacy->name));
        $this->assertTrue($names->contains($this->operatingBranch->name));

        // A branch-bound supervisor sees only that branch.
        $otherBranch = OperatingBranch::create(['operating_company_id' => $this->operatingCompany->id, 'name' => 'Second Branch', 'code' => 'B2', 'is_active' => true]);
        $this->user('Second Branch Tech', User::ROLE_TECHNICIAN, $this->operatingCompany->id, $otherBranch->id);
        $branchAdmin = $this->user('Branch Admin', User::ROLE_ADMIN, $this->operatingCompany->id, $this->operatingBranch->id);
        $this->actingAs($branchAdmin, 'web');
        $scoped = app(PerformanceScoreService::class)->dashboard($branchAdmin, now()->startOfMonth()->toImmutable(), now()->endOfMonth()->toImmutable());
        $technicians = collect($scoped['categories']['technicians'])->pluck('name');
        $this->assertTrue($technicians->contains($this->technician->name));
        $this->assertFalse($technicians->contains('Second Branch Tech'));
        $this->assertSame(['Test Branch'], collect($scoped['categories']['branches'])->pluck('name')->all());
    }

    public function test_metrics_are_aggregated_without_loading_visit_models(): void
    {
        $this->technician->forceFill(['hourly_cost' => 50])->save();
        $base = CarbonImmutable::now()->startOfMonth()->addDays(2)->setTime(9, 0);
        $this->visit->forceFill([
            'scheduled_start' => $base, 'scheduled_end' => $base->addHours(2), 'state' => Visit::STATE_COMPLETED,
            'ended_at' => $base->addHour(), 'closed_at' => $base->addHour(), 'on_site_seconds' => 3600, 'is_rework' => false,
        ])->save();
        $this->visit->workOrder->forceFill(['sla_due_at' => $base->addHours(3), 'resolution_summary' => 'تم'])->save();
        VisitFeedback::create(['visit_id' => $this->visit->id, 'rating' => 4, 'resolution_confirmed' => true, 'is_complaint' => false, 'status' => 'new']);
        for ($i = 1; $i <= 6; $i++) {
            Visit::create([
                'work_order_id' => $this->visit->work_order_id, 'site_id' => $this->site->id,
                'assigned_user_id' => $this->technician->id, 'scheduled_start' => $base->addDays($i),
                'scheduled_end' => $base->addDays($i)->addHour(), 'state' => Visit::STATE_SCHEDULED, 'state_changed_at' => now(),
            ]);
        }

        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.Visit::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        $this->actingAs($this->owner, 'web');
        $dashboard = app(PerformanceScoreService::class)->dashboard($this->owner, $base->startOfMonth(), $base->endOfMonth());

        $this->assertSame(0, $hydrated, 'no Visit model is loaded to compute the rankings');
        $row = collect($dashboard['categories']['technicians'])->firstWhere('id', $this->technician->id);
        $metrics = collect($row['metrics'])->keyBy('key');
        $this->assertSame(1.0, $metrics['completed_visits']['value']);
        $this->assertSame('4.0/5', $metrics['customer_rating']['display']);
        $this->assertSame('50 ر.س', $metrics['cost_per_visit']['display']);
        $this->assertEqualsWithDelta(100 / 7, $metrics['completion_rate']['value'], 0.1);
    }

    private function user(string $name, string $role, ?int $companyId, ?int $branchId): User
    {
        return User::create([
            'name' => $name, 'email' => Str::slug($name).'@test.local', 'password' => Hash::make('Strong-Pass9!'),
            'role' => $role, 'is_active' => true, 'operating_company_id' => $companyId, 'operating_branch_id' => $branchId,
        ]);
    }
}

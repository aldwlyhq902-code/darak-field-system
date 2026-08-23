<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Services\AssetIntelligenceService;
use App\Services\PerformanceScoreService;
use App\Services\ReworkDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

class QueryPerformanceTest extends DarakTestCase
{
    public function test_asset_health_uses_a_constant_number_of_queries(): void
    {
        $queries = $this->countQueries(fn () => app(AssetIntelligenceService::class)->assetHealth());

        $this->assertLessThanOrEqual(8, $queries);
    }

    public function test_client_appointment_capacity_does_not_query_per_slot(): void
    {
        $portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Scheduler',
            'email' => 'scheduler-performance@test.local', 'password' => Hash::make('Strong-Client9!'),
            'is_active' => true, 'permissions' => ['service.request'],
        ]);
        $this->actingAs($portal, 'client');

        $queries = $this->countQueries(fn () => $this->get(route('client.service-request'))->assertOk());

        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_finance_page_batches_profitability_queries(): void
    {
        $this->actingAs($this->owner, 'web');

        $queries = $this->countQueries(fn () => $this->get(route('panel.finance'))->assertOk());

        $this->assertLessThanOrEqual(20, $queries);
    }

    public function test_first_time_fix_summary_uses_one_aggregate_query(): void
    {
        $queries = $this->countQueries(fn () => app(ReworkDetector::class)
            ->firstTimeFixRate(now()->subDays(90), now()));

        $this->assertLessThanOrEqual(1, $queries);
    }

    public function test_board_page_query_count_is_bounded(): void
    {
        $this->actingAs($this->owner, 'web');

        $queries = $this->countQueries(fn () => $this->get(route('panel.board'))->assertOk());

        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_operations_page_query_count_is_bounded(): void
    {
        $this->actingAs($this->owner, 'web');

        $queries = $this->countQueries(fn () => $this->get(route('panel.operations'))->assertOk());

        $this->assertLessThanOrEqual(20, $queries);
    }

    public function test_performance_dashboard_query_count_does_not_scale_per_employee(): void
    {
        $this->actingAs($this->owner, 'web');
        $from = CarbonImmutable::now()->startOfMonth();
        $to = CarbonImmutable::now()->endOfMonth();

        $queries = $this->countQueries(fn () => app(PerformanceScoreService::class)->dashboard($this->owner, $from, $to));

        $this->assertLessThanOrEqual(40, $queries);
    }

    private function countQueries(callable $action): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $action();

        return $queries;
    }
}

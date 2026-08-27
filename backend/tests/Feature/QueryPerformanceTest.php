<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ClientPortalUser;
use App\Models\FaultPredictionModel;
use App\Models\Part;
use App\Models\StockLocation;
use App\Models\StockReservation;
use App\Models\Vehicle;
use App\Services\AssetIntelligenceService;
use App\Services\FaultPredictionTrainer;
use App\Services\PerformanceScoreService;
use App\Services\ReplenishmentService;
use App\Services\ReworkDetector;
use App\Services\VehicleLoadSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public function test_replenishment_balance_queries_do_not_scale_per_part_or_warehouse(): void
    {
        foreach (range(1, 8) as $index) {
            Part::create([
                'sku' => 'PERF-'.$index,
                'name' => 'Performance Part '.$index,
                'purchase_cost' => 10,
                'sale_price' => 20,
                'reorder_level' => 1,
                'is_active' => true,
            ]);
        }
        foreach (range(1, 3) as $index) {
            StockLocation::create([
                'type' => StockLocation::TYPE_WAREHOUSE,
                'name' => 'Performance Warehouse '.$index,
                'operating_branch_id' => $this->operatingBranch->id,
                'is_active' => true,
            ]);
        }

        // Give every pair sufficient stock so this measures the read path only;
        // replenishment writes are necessarily proportional to actual shortages.
        $parts = Part::query()->where('is_active', true)->where('reorder_level', '>', 0)->get();
        $locations = StockLocation::query()->where('type', StockLocation::TYPE_WAREHOUSE)->get();
        foreach ($parts as $part) {
            foreach ($locations as $location) {
                $this->inventory()->receipt((string) Str::uuid(), $part->id, 5, $location->id);
            }
        }

        $queries = $this->countQueries(fn () => app(ReplenishmentService::class)->scan());

        $this->assertLessThanOrEqual(8, $queries);
    }

    public function test_fault_prediction_generation_batches_asset_histories_and_writes(): void
    {
        foreach (range(1, 15) as $index) {
            Asset::create([
                'site_id' => $this->site->id,
                'type' => 'split_ac',
                'name' => 'Prediction Asset '.$index,
                'qr_code' => 'PREDICTION-'.$index,
                'status' => 'active',
            ]);
        }
        $model = FaultPredictionModel::create([
            'coefficients' => [0, 0, 0, 0, 0],
            'feature_scaling' => [],
            'training_samples' => 500,
            'validation_samples' => 100,
            'trained_at' => now(),
            'is_active' => true,
        ]);
        $generate = fn (FaultPredictionModel $predictionModel) => $this->generatePredictions($predictionModel);

        $queries = $this->countQueries(fn () => $generate->call(app(FaultPredictionTrainer::class), $model));

        $this->assertLessThanOrEqual(8, $queries);
        $this->assertDatabaseCount('asset_fault_predictions', 16);
    }

    public function test_vehicle_load_suggestions_batch_technicians_parts_and_balances(): void
    {
        $secondVehicle = Vehicle::create([
            'plate' => 'PERF-LOAD-2',
            'assigned_user_id' => $this->otherTechnician->id,
            'operating_branch_id' => $this->operatingBranch->id,
            'is_active' => true,
        ]);
        StockLocation::create([
            'type' => StockLocation::TYPE_VEHICLE,
            'name' => 'Performance Vehicle 2',
            'vehicle_id' => $secondVehicle->id,
            'operating_branch_id' => $this->operatingBranch->id,
            'is_active' => true,
        ]);
        $firstVisit = $this->visit->forceFill([
            'scheduled_start' => now()->addDay()->startOfDay()->addHours(9),
            'scheduled_end' => now()->addDay()->startOfDay()->addHours(11),
        ]);
        $firstVisit->save();
        $secondVisit = $this->visit->replicate()->forceFill([
            'assigned_user_id' => $this->otherTechnician->id,
            'scheduled_start' => now()->addDay()->startOfDay()->addHours(10),
            'scheduled_end' => now()->addDay()->startOfDay()->addHours(12),
        ]);
        $secondVisit->save();
        foreach ([$firstVisit, $secondVisit] as $visit) {
            StockReservation::create([
                'visit_id' => $visit->id,
                'part_id' => $this->part->id,
                'stock_location_id' => $visit->assigned_user_id === $this->technician->id ? $this->vehicleStock->id : $secondVehicle->stockLocation->id,
                'qty' => 2,
                'status' => 'reserved',
                'created_by' => $this->owner->id,
            ]);
        }

        $suggestions = null;
        $queries = $this->countQueries(function () use (&$suggestions): void {
            $suggestions = app(VehicleLoadSuggestionService::class)->forTomorrow();
        });

        $this->assertLessThanOrEqual(15, $queries);
        $this->assertCount(2, $suggestions);
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

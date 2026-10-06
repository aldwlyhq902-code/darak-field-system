<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\OperationalCost;
use App\Models\Visit;
use App\Services\AdditionalWorkService;
use App\Services\ProfitabilityService;
use App\Services\SubcontractorService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Money computations are exact decimal strings end to end (no 0.30000000000000004). */
class MoneyPrecisionTest extends DarakTestCase
{
    public function test_contract_profitability_sums_components_exactly(): void
    {
        $second = Visit::create([
            'work_order_id' => $this->visit->work_order_id, 'site_id' => $this->site->id,
            'assigned_user_id' => $this->technician->id, 'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(), 'state' => Visit::STATE_SCHEDULED, 'state_changed_at' => now(),
        ]);
        foreach ([[$this->visit, '0.10'], [$second, '0.20']] as [$visit, $amount]) {
            OperationalCost::create([
                'visit_id' => $visit->id, 'contract_id' => $this->contract->id, 'category' => 'travel',
                'description' => 'fuel', 'amount' => $amount, 'incurred_on' => today(), 'recorded_by' => $this->owner->id,
            ]);
        }
        $this->contract->installments()->create([
            'installment_no' => 1, 'due_on' => today(), 'amount' => '0.30', 'vat_amount' => '0.05',
            'total_amount' => '0.35', 'paid_amount' => 0, 'status' => 'pending',
        ]);

        $row = app(ProfitabilityService::class)->forContracts(Contract::whereKey($this->contract->id)->get())[$this->contract->id];

        $this->assertSame('0.30', $row['other'], 'float accumulation gave 0.30000000000000004');
        $this->assertSame('0.30', $row['cost']);
        $this->assertSame('0.30', $row['revenue']);
        $this->assertSame('0.00', $row['profit']);
        $this->assertSame(0.0, $row['margin_percent']);
    }

    public function test_subcontractor_margin_and_additional_work_totals_are_exact_strings(): void
    {
        $preview = app(SubcontractorService::class)->preview('0.10', '0.30');
        $this->assertSame('0.20', $preview['margin']);
        $this->assertSame(66.7, $preview['margin_percent']);

        $approval = app(AdditionalWorkService::class)->create($this->visit, 'Extra', 'Parts', [
            ['description' => 'a', 'qty' => 1, 'unit_price' => '0.10'],
            ['description' => 'b', 'qty' => 1, 'unit_price' => '0.20'],
            ['description' => 'c', 'qty' => 3, 'unit_price' => '33.33'],
        ], $this->owner->id, 'panel');

        $this->assertSame('100.29', (string) $approval->amount);
        $this->assertSame('15.04', (string) $approval->vat_amount, '100.29 × 15% = 15.0435 → 15.04');
        $this->assertSame('115.33', (string) $approval->total_amount);
        $this->assertSame('99.99', $approval->items[2]['line_total']);
    }

    public function test_stock_move_export_prints_exact_two_decimal_costs(): void
    {
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 3, $this->warehouse->id, ['unit_cost' => '12.5']);

        $csv = $this->actingAs($this->owner)->get('/api/v1/reports/stock-moves.csv')->assertOk()->streamedContent();

        $this->assertStringContainsString(',12.50,', $csv);
        $this->assertStringNotContainsString(',12.5,', $csv);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Quotation;
use App\Services\CommercialService;
use App\Support\Money;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Installments must add up to the contract value to the halala (pre-VAT and VAT). */
class ContractScheduleMoneyTest extends DarakTestCase
{
    public function test_monthly_vat_rounding_is_absorbed_by_the_last_installment(): void
    {
        $contract = $this->convert(['price_amount' => '33.33', 'billing_cycle' => 'monthly', 'duration_months' => 12]);

        $rows = $contract->installments;
        $this->assertCount(12, $rows);
        $this->assertSame('399.96', Money::sum($rows->pluck('amount')));
        // 399.96 × 15% = 59.994 → 59.99; twelve rounded 5.00s would bill 60.00.
        $this->assertSame('59.99', Money::sum($rows->pluck('vat_amount')));
        $this->assertSame('5.00', (string) $rows->first()->vat_amount);
        $this->assertSame('4.99', (string) $rows->last()->vat_amount);
        $this->assertSame('459.95', Money::sum($rows->pluck('total_amount')));
    }

    public function test_a_partial_final_quarter_bills_only_its_months(): void
    {
        $contract = $this->convert(['price_amount' => 1000, 'billing_cycle' => 'quarterly', 'duration_months' => 13]);

        $amounts = $contract->installments->pluck('amount')->map(fn ($amount) => (string) $amount)->all();
        $this->assertSame(['3000.00', '3000.00', '3000.00', '3000.00', '1000.00'], $amounts, 'was 5 × 3000 = 15 months billed');
        $this->assertSame('13000.00', Money::sum($amounts));
        $this->assertSame('1950.00', Money::sum($contract->installments->pluck('vat_amount')));
    }

    public function test_a_down_payment_is_part_of_the_contract_value_not_billed_on_top(): void
    {
        $contract = $this->convert(['price_amount' => 100, 'billing_cycle' => 'monthly', 'duration_months' => 12, 'down_payment_amount' => 200]);

        $rows = $contract->installments;
        $this->assertCount(13, $rows);
        $this->assertSame('200.00', (string) $rows->first()->amount);
        $this->assertSame('1200.00', Money::sum($rows->pluck('amount')), 'was 200 + 12 × 100 = 1400');
        $this->assertSame('180.00', Money::sum($rows->pluck('vat_amount')));
        $this->assertSame('83.33', (string) $rows[1]->amount);
        $this->assertSame('83.37', (string) $rows->last()->amount);
    }

    public function test_panel_refuses_a_custom_schedule_that_does_not_match_the_value(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.quotation.store'), [
            'client_id' => $this->client->id, 'title' => 'Custom', 'package_code' => 'basic', 'price_amount' => '1000',
            'vat_rate' => '0.15', 'billing_cycle' => 'custom', 'duration_months' => 12,
            'starts_on' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
            'service_window_start' => '07:00', 'service_window_end' => '23:00', 'sla_minutes' => 240,
            'site_ids' => [$this->site->id], 'down_payment_amount' => '200.10',
            'custom_installments_text' => now()->addMonth()->toDateString()."|300\n".now()->addMonths(2)->toDateString().'|500',
        ])->assertSessionHasErrors('custom_installments_text');

        $this->assertSame(0, Quotation::count());
    }

    /** @param array<string, mixed> $overrides */
    private function convert(array $overrides): Contract
    {
        $quote = Quotation::create(array_merge([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-'.Str::random(6), 'client_id' => $this->client->id,
            'title' => 'Schedule', 'package_code' => 'basic', 'vat_rate' => '0.15',
            'starts_on' => '2026-01-01', 'valid_until' => now()->addMonth()->toDateString(),
            'status' => 'accepted', 'created_by' => $this->owner->id,
        ], $overrides));
        $quote->sites()->attach($this->site);

        return app(CommercialService::class)->convertQuotation($quote, $this->owner->id)->load('installments');
    }
}

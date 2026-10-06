<?php

namespace Tests\Feature;

use App\Models\FinancialApproval;
use App\Models\Quotation;
use App\Models\User;
use App\Services\FinancialApprovalService;
use App\Services\QuotationPricingService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\DarakTestCase;

/**
 * M5: a non-owner cannot quote below the price list and send it straight to
 * the client, and an approved discount only ever changes an open offer.
 */
class QuotationPriceControlFindingsTest extends DarakTestCase
{
    private User $marketer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'darak.quotation_pricing.list_prices.basic' => 1200,
            'darak.quotation_pricing.list_prices.comprehensive' => null,
            'darak.quotation_pricing.max_discount_percent' => 0,
        ]);
        $this->marketer = $this->panelUser('marketer@test.local', ['sales', 'commercial']);
        $this->approver = $this->panelUser('approver@test.local', ['admin']);
    }

    public function test_marketer_quote_below_list_price_waits_for_dual_approval_before_sending(): void
    {
        $this->actingAs($this->marketer, 'web')
            ->post(route('sales.quotations.store'), $this->quotationForm(['price_amount' => 900]))
            ->assertRedirect()->assertSessionHas('ok');
        $quotation = Quotation::where('created_by', $this->marketer->id)->firstOrFail();
        $approval = FinancialApproval::where('subject_type', 'quotation')->where('subject_id', $quotation->id)
            ->where('action_type', QuotationPricingService::ACTION)->firstOrFail();
        $this->assertSame('pending_first', $approval->status);
        $this->assertSame(900.0, (float) $approval->amount);

        $this->post(route('sales.quotations.send', $quotation))->assertRedirect()->assertSessionHas('err');
        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->fresh()->status);

        app(FinancialApprovalService::class)->approve($approval, $this->owner->id);
        app(FinancialApprovalService::class)->approve($approval, $this->approver->id);
        $this->assertSame(900.0, (float) $quotation->fresh()->price_amount);

        $this->post(route('sales.quotations.send', $quotation))->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(Quotation::STATUS_SENT, $quotation->fresh()->status);
    }

    public function test_list_price_quotes_send_freely_and_unpriced_packages_always_need_approval(): void
    {
        $this->actingAs($this->marketer, 'web')
            ->post(route('sales.quotations.store'), $this->quotationForm(['price_amount' => 1200, 'title' => 'At list']))
            ->assertSessionHas('ok');
        $atList = Quotation::where('title', 'At list')->firstOrFail();
        $this->post(route('sales.quotations.send', $atList))->assertSessionHas('ok');
        $this->assertSame(Quotation::STATUS_SENT, $atList->fresh()->status);

        // No list price for this package: any non-owner price needs approval,
        // also on the back-office commercial panel.
        $this->post(route('panel.quotation.store'), $this->quotationForm([
            'package_code' => 'comprehensive', 'price_amount' => 99999, 'title' => 'Unpriced', 'vat_rate' => .15,
        ]))->assertSessionHas('ok');
        $unpriced = Quotation::where('title', 'Unpriced')->firstOrFail();
        $this->post(route('panel.quotation.send', $unpriced))->assertSessionHas('err');
        $this->assertSame(Quotation::STATUS_DRAFT, $unpriced->fresh()->status);

        // The owner sets prices: its own quote needs no approval.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.quotation.store'), $this->quotationForm([
                'package_code' => 'comprehensive', 'price_amount' => 10, 'title' => 'Owner priced', 'vat_rate' => .15,
            ]))->assertSessionHas('ok');
        $ownerQuote = Quotation::where('title', 'Owner priced')->firstOrFail();
        $this->post(route('panel.quotation.send', $ownerQuote))->assertSessionHas('ok');
        $this->assertSame(0, FinancialApproval::where('subject_id', $ownerQuote->id)->count());
    }

    public function test_approved_discount_extends_an_acceptable_price_but_never_launders_an_unapproved_one(): void
    {
        $atList = $this->quotation(['price_amount' => 1200, 'created_by' => $this->marketer->id]);
        $this->approveDiscount($atList, 100);
        $this->assertSame(1100.0, (float) $atList->fresh()->price_amount);
        $this->assertTrue(app(QuotationPricingService::class)->isApproved($atList->fresh()));

        $belowList = $this->quotation(['price_amount' => 500, 'created_by' => $this->marketer->id]);
        $this->approveDiscount($belowList, 1);
        $this->assertSame(499.0, (float) $belowList->fresh()->price_amount);
        $this->actingAs($this->marketer, 'web')
            ->post(route('sales.quotations.send', $belowList))->assertSessionHas('err');
        $this->assertSame(Quotation::STATUS_DRAFT, $belowList->fresh()->status);
    }

    public function test_discount_is_never_applied_to_an_accepted_converted_or_expired_quotation(): void
    {
        $closed = [
            $this->quotation(['status' => Quotation::STATUS_ACCEPTED, 'accepted_at' => now()]),
            $this->quotation(['status' => Quotation::STATUS_CONVERTED, 'converted_contract_id' => $this->contract->id]),
            $this->quotation(['status' => Quotation::STATUS_SENT, 'valid_until' => now()->subDays(2)->toDateString()]),
        ];

        foreach ($closed as $quotation) {
            $approval = $this->discountApproval($quotation, 100);
            app(FinancialApprovalService::class)->approve($approval, $this->owner->id);
            try {
                app(FinancialApprovalService::class)->approve($approval, $this->approver->id);
                $this->fail("A discount was applied to a {$quotation->status} quotation.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('لا يمكن تطبيق الخصم', $e->getMessage());
            }
            $this->assertSame(1200.0, (float) $quotation->fresh()->price_amount);
            $this->assertSame('pending_second', $approval->fresh()->status);
        }

        // Refused up front as well, with the same explanation.
        $this->actingAs($this->owner, 'web')
            ->post(route('sales.quotations.discount', $closed[0]), ['amount' => 50, 'reason' => 'late'])
            ->assertSessionHas('err');
        $this->assertSame(3, FinancialApproval::count());
    }

    private function approveDiscount(Quotation $quotation, float $amount): void
    {
        $approval = $this->discountApproval($quotation, $amount);
        app(FinancialApprovalService::class)->approve($approval, $this->owner->id);
        app(FinancialApprovalService::class)->approve($approval, $this->approver->id);
    }

    private function discountApproval(Quotation $quotation, float $amount): FinancialApproval
    {
        return FinancialApproval::create([
            'public_reference' => (string) Str::uuid(), 'action_type' => 'discount', 'subject_type' => 'quotation',
            'subject_id' => $quotation->id, 'amount' => $amount, 'reason' => 'Retention', 'status' => 'pending_first',
            'requested_by' => $this->marketer->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function quotation(array $overrides = []): Quotation
    {
        return Quotation::create($overrides + [
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-'.Str::upper(Str::random(8)),
            'client_id' => $this->client->id, 'title' => 'Quote', 'package_code' => 'basic',
            'price_amount' => 1200, 'vat_rate' => .15, 'billing_cycle' => 'monthly', 'duration_months' => 12,
            'starts_on' => now(), 'valid_until' => now()->addMonth()->toDateString(), 'service_window_start' => '07:00',
            'service_window_end' => '23:00', 'sla_minutes' => 240, 'status' => Quotation::STATUS_DRAFT,
            'created_by' => $this->owner->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function quotationForm(array $overrides = []): array
    {
        return $overrides + [
            'client_id' => $this->client->id, 'site_ids' => [$this->site->id], 'title' => 'Maintenance offer',
            'package_code' => 'basic', 'price_amount' => 1200, 'vat_rate' => .15, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'starts_on' => now()->addWeek()->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(), 'service_window_start' => '07:00',
            'service_window_end' => '23:00', 'sla_minutes' => 240,
        ];
    }

    /** @param array<int, string> $permissions */
    private function panelUser(string $email, array $permissions): User
    {
        $user = User::create([
            'name' => 'User '.$email, 'email' => $email, 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'permissions' => $permissions, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $this->operatingBranch->id,
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}

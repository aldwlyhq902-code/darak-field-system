<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\CommercialService;
use App\Services\CommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** PRD v1.2: accrued → earned → paid, reversed inside the clawback window, never from wages. */
class CommissionLifecycleTest extends DarakTestCase
{
    private User $marketer;

    private Contract $soldContract;

    private CommissionRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['darak.commission_clawback_days' => 30]);

        $this->marketer = User::create([
            'name' => 'Marketer', 'email' => 'marketer@test.local', 'password' => Hash::make('Strong-Sales9!'),
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'operating_company_id' => $this->operatingCompany->id,
        ]);
        $quote = Quotation::create([
            'series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-COMM', 'client_id' => $this->client->id,
            'title' => 'Sold', 'package_code' => 'basic', 'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'upfront',
            'duration_months' => 12, 'starts_on' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
            'status' => 'accepted', 'created_by' => $this->marketer->id,
        ]);
        $quote->sites()->attach($this->site);
        $this->soldContract = app(CommercialService::class)->convertQuotation($quote, $this->owner->id);
        $this->rule = CommissionRule::create([
            'name' => 'Collection 5%', 'applies_to_role' => User::ROLE_ADMIN, 'basis' => 'collected_payment',
            'rate' => 5, 'fixed_amount' => 0, 'is_active' => true,
        ]);
    }

    public function test_a_receipt_accrues_immediately_and_earns_only_after_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00'));
        $payment = $this->collect('400.10');

        $entry = CommissionEntry::where('payment_id', $payment->id)->sole();
        $this->assertSame(CommissionEntry::STATUS_ACCRUED, $entry->status);
        $this->assertSame('20.01', (string) $entry->commission_amount, '5% of 400.10 = 20.005 → half-up');
        $this->assertNotNull($entry->accrued_at);

        $this->travelTo(CarbonImmutable::parse('2026-03-30 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $this->assertSame(CommissionEntry::STATUS_ACCRUED, $entry->refresh()->status, 'still inside the window');

        $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $this->assertSame(CommissionEntry::STATUS_EARNED, $entry->refresh()->status);
        $this->assertNotNull($entry->earned_at);
        $this->assertSame(1, CommissionEntry::count(), 'the nightly run stays idempotent');
    }

    public function test_refund_inside_the_window_reverses_but_after_the_window_the_commission_stands(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00'));
        $early = $this->collect('100');
        $late = $this->collect('200');

        $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00'));
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.payment.refund', $early), ['reason' => 'شيك مرتجع'])
            ->assertRedirect()->assertSessionHas('ok');

        $reversed = CommissionEntry::where('payment_id', $early->id)->sole();
        $this->assertSame(CommissionEntry::STATUS_REVERSED, $reversed->status);
        $this->assertNotNull($early->refresh()->refunded_at);
        $this->assertSame('200.00', (string) ContractInstallment::where('contract_id', $this->soldContract->id)->sole()->paid_amount);

        $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        app(CommercialService::class)->refundPayment($late, 'late refund', $this->owner->id);

        $this->assertSame(CommissionEntry::STATUS_EARNED, CommissionEntry::where('payment_id', $late->id)->sole()->status);
        $this->assertSame(CommissionEntry::STATUS_REVERSED, $reversed->refresh()->status, 'never promoted after reversal');
        $this->assertSame(0, CommissionEntry::where('entry_type', CommissionEntry::TYPE_ADJUSTMENT)->count());
    }

    public function test_a_clawback_after_payout_only_offsets_future_commissions_and_never_goes_negative(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00'));
        $this->collect('1000');                       // 50.00 commission
        $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $first = CommissionEntry::sole();

        $this->actingAs($this->owner, 'web')->post(route('panel.commissions.payout'), [
            'entry_ids' => [$first->id], 'reference' => 'TRF-001', 'paid_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(CommissionEntry::STATUS_PAID, $first->refresh()->status);
        $this->assertSame('50.00', (string) CommissionPayout::sole()->net_amount);

        // A cancellation effective inside the window is recorded after payout.
        $adjustments = app(CommissionService::class)->reverseForContract(
            $this->soldContract, 'إلغاء العقد', CarbonImmutable::parse('2026-03-20'),
        );
        $this->assertCount(1, $adjustments);
        $adjustment = $adjustments[0];
        $this->assertSame(CommissionEntry::TYPE_ADJUSTMENT, $adjustment->entry_type);
        $this->assertSame('-50.00', (string) $adjustment->commission_amount);
        $this->assertSame(CommissionEntry::STATUS_PAID, $first->refresh()->status, 'the paid entry is history, not edited');

        // Next commission (30.00) is smaller than the clawback: payout nets to zero, never below.
        $this->travelTo(CarbonImmutable::parse('2026-04-03 10:00'));
        $this->collect('600');
        $this->travelTo(CarbonImmutable::parse('2026-05-05 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $second = CommissionEntry::where('entry_type', CommissionEntry::TYPE_COMMISSION)->where('status', CommissionEntry::STATUS_EARNED)->sole();
        $this->post(route('panel.commissions.payout'), [
            'entry_ids' => [$second->id], 'reference' => 'TRF-002', 'paid_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $payout = CommissionPayout::where('reference', 'TRF-002')->sole();
        $this->assertSame('30.00', (string) $payout->gross_amount);
        $this->assertSame('30.00', (string) $payout->offset_amount);
        $this->assertSame('0.00', (string) $payout->net_amount);
        $this->assertSame('30.00', (string) $adjustment->refresh()->offset_amount);
        $this->assertSame(CommissionEntry::STATUS_EARNED, $adjustment->status, '20.00 still outstanding');

        // The remainder comes off the following payout only.
        $this->travelTo(CarbonImmutable::parse('2026-05-06 10:00'));
        $this->collect('1000');
        $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00'));
        $this->artisan('darak:commissions-generate')->assertSuccessful();
        $third = CommissionEntry::where('entry_type', CommissionEntry::TYPE_COMMISSION)->where('status', CommissionEntry::STATUS_EARNED)->sole();
        $this->post(route('panel.commissions.payout'), [
            'entry_ids' => [$third->id], 'reference' => 'TRF-003', 'paid_on' => today()->toDateString(),
        ])->assertRedirect();

        $this->assertSame('30.00', (string) CommissionPayout::where('reference', 'TRF-003')->sole()->net_amount);
        $this->assertSame(CommissionEntry::STATUS_PAID, $adjustment->refresh()->status);
        $this->assertSame(0, CommissionPayout::where('net_amount', '<', 0)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission.payout_recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission.clawback_adjustment']);
    }

    public function test_only_earned_entries_can_be_paid_and_only_by_an_owner_of_the_same_company(): void
    {
        $payment = $this->collect('100');
        $accrued = CommissionEntry::where('payment_id', $payment->id)->sole();

        $this->actingAs($this->owner, 'web')->post(route('panel.commissions.payout'), [
            'entry_ids' => [$accrued->id], 'reference' => 'EARLY', 'paid_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('err');
        $this->assertSame(CommissionEntry::STATUS_ACCRUED, $accrued->refresh()->status);

        $accrued->forceFill(['status' => CommissionEntry::STATUS_EARNED])->save();
        $outsider = $this->otherCompanyOwner();
        $this->actingAs($outsider, 'web')->post(route('panel.commissions.payout'), [
            'entry_ids' => [$accrued->id], 'reference' => 'HIJACK', 'paid_on' => today()->toDateString(),
        ])->assertNotFound();
        $this->get(route('panel.commissions'))->assertOk()->assertDontSee('Marketer');
        $this->assertSame(0, CommissionPayout::count());

        $this->actingAs($this->owner, 'web')->get(route('panel.commissions'))->assertOk()
            ->assertSee('العمولات وصرفها')->assertSee('Marketer');
    }

    public function test_legacy_pending_entries_migrate_to_accrued(): void
    {
        $migration = require database_path('migrations/2026_10_06_200000_add_commission_lifecycle.php');
        $migration->down();
        DB::table('commission_entries')->insert([
            'commission_rule_id' => $this->rule->id, 'user_id' => $this->marketer->id,
            'basis_amount' => 100, 'commission_amount' => 5, 'status' => 'pending',
            'created_at' => '2026-01-05 09:00:00', 'updated_at' => '2026-01-05 09:00:00',
        ]);
        $migration->up();

        $row = DB::table('commission_entries')->sole();
        $this->assertSame('accrued', $row->status);
        $this->assertSame('2026-01-05 09:00:00', substr((string) $row->accrued_at, 0, 19));
    }

    private function collect(string $amount): Payment
    {
        $installment = ContractInstallment::where('contract_id', $this->soldContract->id)->sole();
        $installment->forceFill(['total_amount' => 100000, 'status' => 'pending'])->save();

        return app(CommercialService::class)->recordPayment($installment, [
            'amount' => $amount, 'paid_on' => today()->toDateString(), 'method' => 'bank_transfer',
        ], $this->owner->id);
    }

    private function otherCompanyOwner(): User
    {
        $company = OperatingCompany::create(['name' => 'Other Tenant', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
        Client::withoutGlobalScopes()->create([
            'name' => 'Other Client', 'category' => 'restaurant', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $owner = User::create([
            'name' => 'Other Owner', 'email' => 'other-owner@test.local', 'password' => Hash::make('Strong-Owner9!'),
            'role' => User::ROLE_OWNER, 'is_active' => true, 'operating_company_id' => $company->id,
        ]);
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();

        return $owner;
    }
}

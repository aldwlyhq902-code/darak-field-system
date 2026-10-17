<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinancialApproval;
use App\Models\User;
use App\Services\FinancialApprovalService;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/**
 * A contract entered straight from the client page skipped every price rule a
 * quotation obeys: anyone with the `clients` permission could put a contract
 * live at any price, zero included, with no second pair of eyes.
 */
class DirectContractApprovalTest extends DarakTestCase
{
    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'darak.quotation_pricing.list_prices.basic' => 1200,
            'darak.quotation_pricing.price_floor' => 500,
            'darak.quotation_pricing.max_discount_percent' => 10,
        ]);
        $this->clerk = $this->panelAdmin('clerk@test.local');
    }

    public function test_a_non_owner_at_list_price_goes_live(): void
    {
        $this->createAs($this->clerk, 1200)->assertSessionHas('ok');

        $this->assertSame('active', $this->latestContract()->status);
        $this->assertSame(0, FinancialApproval::count());
    }

    public function test_a_non_owner_below_the_rules_waits_for_dual_approval(): void
    {
        $this->createAs($this->clerk, 0)->assertSessionHas('ok');

        $contract = $this->latestContract();
        $this->assertSame(Contract::STATUS_PENDING_APPROVAL, $contract->status);
        $this->assertFalse($contract->isActive());

        $approval = FinancialApproval::where('subject_type', 'contract')->where('subject_id', $contract->id)->sole();
        $this->assertSame('pending_first', $approval->status);
        $this->assertSame($this->clerk->id, $approval->requested_by);

        $approvals = app(FinancialApprovalService::class);
        $approvals->approve($approval, $this->owner->id);
        $this->assertSame(Contract::STATUS_PENDING_APPROVAL, $contract->refresh()->status, 'one approval is not enough');

        $approvals->approve($approval->refresh(), $this->panelAdmin('second@test.local')->id);
        $this->assertSame('active', $contract->refresh()->status);
    }

    public function test_the_requester_cannot_approve_their_own_contract_price(): void
    {
        $this->createAs($this->clerk, 300);
        $approval = FinancialApproval::where('subject_type', 'contract')->sole();

        $this->expectException(\RuntimeException::class);
        app(FinancialApprovalService::class)->approve($approval, $this->clerk->id);
    }

    public function test_the_owner_prices_freely(): void
    {
        $this->createAs($this->owner, 0)->assertSessionHas('ok');

        $this->assertSame('active', $this->latestContract()->status);
        $this->assertSame(0, FinancialApproval::count());
    }

    private function createAs(User $user, float $price)
    {
        return $this->actingAs($user, 'web')->post(route('panel.client.contract', $this->client), [
            'package_code' => 'basic',
            'price_amount' => $price,
            'starts_on' => now()->toDateString(),
            'sla_minutes' => 240,
            'service_window_start' => '07:00',
            'service_window_end' => '23:00',
            'site_ids' => [$this->site->id],
        ]);
    }

    private function latestContract(): Contract
    {
        return Contract::query()->latest('id')->firstOrFail();
    }

    private function panelAdmin(string $email): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'permissions' => ['clients', 'admin'],
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}

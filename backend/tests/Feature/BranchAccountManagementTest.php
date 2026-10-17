<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OperatingBranch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Data reads already stopped at the branch; acting on accounts did not. A
 * branch-bound supervisor could disable the company-wide owner or another
 * branch's staff, reset their MFA (which also ends their sessions) and change
 * their panel permissions.
 */
class BranchAccountManagementTest extends DarakTestCase
{
    private OperatingBranch $otherBranch;

    private User $branchOwner;

    private User $otherBranchTechnician;

    private User $otherBranchAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherBranch = OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id,
            'name' => 'North', 'code' => 'NORTH', 'is_active' => true,
        ]);
        $this->branchOwner = $this->panelUser('branch-owner@test.local', User::ROLE_OWNER, $this->operatingBranch->id);
        $this->otherBranchAdmin = $this->panelUser('north-admin@test.local', User::ROLE_ADMIN, $this->otherBranch->id);
        $this->otherBranchTechnician = User::create([
            'name' => 'North Tech', 'email' => 'north-tech@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_TECHNICIAN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'operating_branch_id' => $this->otherBranch->id,
        ]);
    }

    public function test_a_branch_owner_cannot_disable_another_branchs_staff(): void
    {
        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.toggle', $this->otherBranchTechnician))
            ->assertNotFound();

        $this->assertTrue($this->otherBranchTechnician->refresh()->is_active);
    }

    public function test_a_branch_owner_cannot_touch_the_company_wide_owner(): void
    {
        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.toggle', $this->owner))
            ->assertNotFound(); // the company-wide owner sits in no branch

        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.two-factor-reset', $this->owner))
            ->assertNotFound();

        $this->assertTrue($this->owner->refresh()->is_active);
        $this->assertTrue($this->owner->hasConfirmedTwoFactor());
    }

    public function test_a_branch_owner_cannot_reset_mfa_or_permissions_across_branches(): void
    {
        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.two-factor-reset', $this->otherBranchAdmin))
            ->assertNotFound();
        $this->assertTrue($this->otherBranchAdmin->refresh()->hasConfirmedTwoFactor());

        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.admin.permissions', $this->otherBranchAdmin), ['full_access' => 1])
            ->assertNotFound();
        $this->assertNotSame(['*'], $this->otherBranchAdmin->refresh()->permissions);
    }

    public function test_a_branch_owner_cannot_revoke_another_branchs_device(): void
    {
        $device = Device::create(['user_id' => $this->otherBranchTechnician->id, 'device_uuid' => (string) Str::uuid(), 'platform' => 'android']);

        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.revoke', $device))
            ->assertNotFound();

        $this->assertNull($device->refresh()->revoked_at);
    }

    public function test_a_branch_owner_still_manages_their_own_branch(): void
    {
        $this->actingAs($this->branchOwner, 'web')
            ->post(route('panel.team.toggle', $this->technician))
            ->assertSessionHas('ok');

        $this->assertFalse($this->technician->refresh()->is_active);
    }

    public function test_the_team_page_lists_only_the_actors_branch(): void
    {
        $this->actingAs($this->branchOwner, 'web')->get(route('panel.team'))
            ->assertOk()
            ->assertSee($this->technician->name)
            ->assertDontSee($this->otherBranchTechnician->name)
            ->assertDontSee($this->otherBranchAdmin->email);
    }

    public function test_the_company_wide_owner_manages_every_branch(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.toggle', $this->otherBranchTechnician))
            ->assertSessionHas('ok');

        $this->assertFalse($this->otherBranchTechnician->refresh()->is_active);
    }

    private function panelUser(string $email, string $role, int $branchId): User
    {
        $user = User::create([
            'name' => Str::before($email, '@'), 'email' => $email, 'password' => Hash::make('secret'),
            'role' => $role, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'operating_branch_id' => $branchId,
            'permissions' => $role === User::ROLE_ADMIN ? ['team'] : null,
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}

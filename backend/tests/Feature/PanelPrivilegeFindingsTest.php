<?php

namespace Tests\Feature;

use App\Models\OperatingBranch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/**
 * M1 and the permission fallback: a panel admin must not be able to widen its
 * own data boundary, re-scope the owner, or end up with full access because a
 * multi-select was submitted empty.
 */
class PanelPrivilegeFindingsTest extends DarakTestCase
{
    public function test_branch_admin_cannot_escape_its_branch_or_rescope_anyone(): void
    {
        $sibling = $this->siblingBranch();
        $admin = $this->panelAdmin('branch-admin@test.local', null, $this->operatingBranch->id);

        $this->actingAs($admin, 'web');
        // Widening itself to company-wide.
        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'user', 'entity_id' => $admin->id, 'operating_branch_id' => ''])
            ->assertForbidden();
        // Confining the owner to a branch.
        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'user', 'entity_id' => $this->owner->id, 'operating_branch_id' => $this->operatingBranch->id])
            ->assertForbidden();
        // Moving a technician (or a client) out to another branch.
        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'user', 'entity_id' => $this->technician->id, 'operating_branch_id' => $sibling->id])
            ->assertForbidden();
        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'client', 'entity_id' => $this->client->id, 'operating_branch_id' => $sibling->id])
            ->assertForbidden();

        $this->assertSame($this->operatingBranch->id, $admin->fresh()->operating_branch_id);
        $this->assertNull($this->owner->fresh()->operating_branch_id);
        $this->assertSame($this->operatingBranch->id, $this->technician->fresh()->operating_branch_id);
        $this->assertSame($this->operatingBranch->id, $this->client->fresh()->operating_branch_id);
    }

    public function test_owner_moves_people_between_its_branches_but_never_itself(): void
    {
        $sibling = $this->siblingBranch();
        $this->actingAs($this->owner, 'web');

        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'user', 'entity_id' => $this->owner->id, 'operating_branch_id' => $sibling->id])
            ->assertForbidden();
        $this->assertNull($this->owner->fresh()->operating_branch_id);

        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'user', 'entity_id' => $this->technician->id, 'operating_branch_id' => $sibling->id])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertSame($sibling->id, $this->technician->fresh()->operating_branch_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenancy.branch_assigned', 'user_id' => $this->owner->id]);
    }

    public function test_empty_permission_selection_grants_nothing_and_full_access_is_explicit(): void
    {
        $admin = $this->panelAdmin('scoped-admin@test.local', ['sales'], $this->operatingBranch->id);
        $this->actingAs($this->owner, 'web');

        $this->get(route('panel.admin-operations'))->assertOk()
            ->assertSee('<option value="sales" selected>', false)
            ->assertSee('name="full_access"', false);

        $this->post(route('panel.admin.permissions', $admin), [])->assertRedirect();
        $this->assertSame([], $admin->fresh()->permissions);
        $this->assertFalse($admin->fresh()->canPanel('operations'));
        $this->assertFalse($admin->fresh()->canPanel('sales'));

        $this->post(route('panel.admin.permissions', $admin), ['permissions' => ['finance']])->assertRedirect();
        $this->assertSame(['finance'], $admin->fresh()->permissions);

        $this->post(route('panel.admin.permissions', $admin), ['full_access' => '1'])->assertRedirect();
        $this->assertSame(['*'], $admin->fresh()->permissions);
        $this->assertTrue($admin->fresh()->canPanel('admin'));
    }

    private function siblingBranch(): OperatingBranch
    {
        return OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'Sibling Branch', 'code' => 'SIB', 'is_active' => true,
        ]);
    }

    /** @param array<int, string>|null $permissions */
    private function panelAdmin(string $email, ?array $permissions, ?int $branchId): User
    {
        $admin = User::create([
            'name' => 'Admin '.$email, 'email' => $email, 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'permissions' => $permissions, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $branchId,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}

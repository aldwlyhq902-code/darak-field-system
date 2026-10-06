<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/** M3: the visit API is no more permissive than the panel, and vice versa. */
class VisitAccessFindingsTest extends DarakTestCase
{
    public function test_admin_without_operations_permission_has_no_visit_api_access(): void
    {
        $sales = $this->admin('sales-only@test.local', ['sales']);

        $this->actingAs($sales)->getJson('/api/v1/visits')->assertForbidden();
        $this->actingAs($sales)->getJson("/api/v1/visits/{$this->visit->id}")->assertForbidden();
        $this->actingAs($sales)->get("/api/v1/visits/{$this->visit->id}/report.pdf")->assertForbidden();
        $this->actingAs($sales)->postJson("/api/v1/visits/{$this->visit->id}/diagnosis", [
            'fault_code' => 'E1', 'diagnosis_code' => 'CAP',
        ])->assertForbidden();

        $this->assertNull($this->visit->workOrder()->first()->diagnosis_code);
    }

    public function test_operations_admin_assigns_on_api_like_the_panel_but_rework_override_is_owner_only_everywhere(): void
    {
        $operations = $this->admin('ops-admin@test.local', ['operations']);
        $this->otherTechnician->forceFill(['shift_start' => null, 'shift_end' => null])->save();
        $this->actingAs($operations)->getJson('/api/v1/visits')->assertOk();
        $this->actingAs($operations)->postJson("/api/v1/visits/{$this->visit->id}/assign", ['user_id' => $this->otherTechnician->id])
            ->assertOk();
        $this->assertSame($this->otherTechnician->id, $this->visit->fresh()->assigned_user_id);

        $this->visit->forceFill(['is_rework' => true, 'rework_system_flagged' => true, 'is_billable' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($operations, 'web')
            ->post(route('panel.visit.rework', $this->visit), ['reason' => 'unrelated', 'note' => 'Make it billable'])
            ->assertForbidden();
        $this->assertTrue($this->visit->fresh()->is_rework);
        $this->assertFalse($this->visit->fresh()->is_billable);
        $this->get(route('panel.visit', $this->visit))->assertOk()->assertDontSee('إعادة التصنيف');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.visit.rework', $this->visit), ['reason' => 'unrelated', 'note' => 'Different unit'])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertFalse($this->visit->fresh()->is_rework);
    }

    /** @param array<int, string> $permissions */
    private function admin(string $email, array $permissions): User
    {
        $admin = User::create([
            'name' => 'Admin '.$email, 'email' => $email, 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'permissions' => $permissions, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}

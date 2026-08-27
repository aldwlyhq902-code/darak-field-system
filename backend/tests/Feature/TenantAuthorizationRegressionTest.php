<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Device;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class TenantAuthorizationRegressionTest extends DarakTestCase
{
    public function test_tenant_owner_cannot_manage_another_company_users_devices_or_portal_accounts(): void
    {
        [$otherOwner, $otherClient, $otherDevice] = $this->otherTenant();
        $portal = ClientPortalUser::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Portal',
            'email' => 'other-portal@test.local',
            'password' => Hash::make('Strong-Client9!'),
            'is_active' => true,
        ]);

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.team'))
            ->assertOk()
            ->assertDontSee($otherOwner->email);

        $this->post(route('panel.team.toggle', $otherOwner))->assertNotFound();
        $this->post(route('panel.team.revoke', $otherDevice))->assertNotFound();
        $this->post(route('panel.team.two-factor-reset', $otherOwner))->assertNotFound();
        $this->post(route('panel.client.portal-user.toggle', $portal))->assertNotFound();

        $this->assertTrue($otherOwner->fresh()->is_active);
        $this->assertNull($otherDevice->fresh()->revoked_at);
        $this->assertTrue($portal->fresh()->is_active);
        $this->assertTrue($otherOwner->fresh()->hasConfirmedTwoFactor());
    }

    public function test_tenant_owner_cannot_edit_or_create_outside_its_company(): void
    {
        [, , , $otherCompany] = $this->otherTenant();

        $this->actingAs($this->owner, 'web')
            ->put(route('panel.admin.company.update', $otherCompany), [
                'name' => 'Hijacked',
                'currency' => 'SAR',
            ])->assertForbidden();

        $this->post(route('panel.admin.company'), [
            'name' => 'Unauthorized company',
            'currency' => 'SAR',
        ])->assertForbidden();

        $this->post(route('panel.admin.branch'), [
            'operating_company_id' => $otherCompany->id,
            'name' => 'Unauthorized branch',
            'code' => 'NOPE',
        ])->assertForbidden();

        $this->assertSame($otherCompany->name, $otherCompany->fresh()->name);
        $this->assertDatabaseMissing('operating_companies', ['name' => 'Unauthorized company']);
        $this->assertDatabaseMissing('operating_branches', ['code' => 'NOPE']);
    }

    /** @return array{User, Client, Device, OperatingCompany} */
    private function otherTenant(): array
    {
        $company = OperatingCompany::create(['name' => 'Other Tenant', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create([
            'operating_company_id' => $company->id,
            'name' => 'Other Branch',
            'code' => 'OTHER-TENANT',
            'is_active' => true,
        ]);
        $owner = User::create([
            'name' => 'Other Owner',
            'email' => 'other-owner@test.local',
            'password' => Hash::make('Strong-Owner9!'),
            'role' => User::ROLE_OWNER,
            'operating_company_id' => $company->id,
            'operating_branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $client = Client::withoutGlobalScopes()->create([
            'name' => 'Other Client',
            'category' => 'restaurant',
            'payment_term' => 'monthly',
            'operating_company_id' => $company->id,
            'operating_branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $device = Device::create([
            'user_id' => $owner->id,
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ]);

        return [$owner, $client, $device, $company];
    }
}

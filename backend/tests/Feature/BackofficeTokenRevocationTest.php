<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\DarakTestCase;

/** Review finding M3 (token part): MFA reset and pre-fix tokens. */
class BackofficeTokenRevocationTest extends DarakTestCase
{
    public function test_mfa_reset_also_revokes_the_accounts_api_tokens(): void
    {
        $admin = $this->admin();
        $plain = $admin->createToken('device:admin-phone')->plainTextToken;
        $technicianToken = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.two-factor-reset', $admin))
            ->assertRedirect();

        $this->assertSame(0, $admin->tokens()->count());
        $this->asToken($plain)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($technicianToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_command_revokes_only_backoffice_tokens(): void
    {
        $admin = $this->admin();
        $admin->createToken('device:admin-phone');
        $this->owner->createToken('device:owner-phone');
        $this->technician->createToken('device:'.$this->device->device_uuid);

        $this->artisan('darak:revoke-backoffice-tokens', ['--dry-run' => true])
            ->expectsOutputToContain('2 back-office API token(s) would be revoked.')
            ->assertSuccessful();
        $this->assertSame(3, PersonalAccessToken::count());

        $this->artisan('darak:revoke-backoffice-tokens', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(1, $this->technician->tokens()->count());
    }

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.local', 'password' => Hash::make('Strong-Test9!'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}

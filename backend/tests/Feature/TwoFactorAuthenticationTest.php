<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

class TwoFactorAuthenticationTest extends DarakTestCase
{
    public function test_first_panel_login_requires_two_factor_enrollment(): void
    {
        $admin = $this->unconfirmedAdmin();

        $this->post(route('panel.login'), [
            'email' => $admin->email,
            'password' => 'Strong-Test9!',
        ])->assertRedirect(route('panel.two-factor.setup'));

        $this->assertAuthenticatedAs($admin, 'web');
        $this->get(route('panel.board'))->assertRedirect(route('panel.two-factor.setup'));

        $this->get(route('panel.two-factor.setup'))
            ->assertOk()
            ->assertSee('رمز QR لإعداد التحقق بخطوتين')
            ->assertSee('data:image/svg+xml;base64,', false);
        $admin->refresh();
        $secret = $admin->two_factor_secret;

        $response = $this->post(route('panel.two-factor.confirm'), [
            'code' => app(TwoFactorService::class)->currentCode($admin),
        ]);

        $response->assertOk()->assertSee('رموز الاسترداد');
        $response->assertDontSee($secret);
        $admin->refresh();
        $this->assertTrue($admin->hasConfirmedTwoFactor());
        $this->assertCount(8, $admin->two_factor_recovery_codes);
        foreach ($admin->two_factor_recovery_codes as $digest) {
            $this->assertStringStartsWith('hmac:', $digest);
        }

        $stored = DB::table('users')->where('id', $admin->id)->first();
        $this->assertNotSame($secret, $stored->two_factor_secret);
        $this->assertNotSame(json_encode($admin->two_factor_recovery_codes), $stored->two_factor_recovery_codes);
        foreach ($admin->two_factor_recovery_codes as $hash) {
            $response->assertDontSee($hash);
        }
    }

    public function test_password_alone_does_not_authenticate_a_confirmed_admin(): void
    {
        $this->post(route('panel.login'), [
            'email' => $this->owner->email,
            'password' => 'secret',
        ])->assertRedirect(route('panel.two-factor.challenge'));

        $this->assertGuest('web');
        $this->get(route('panel.board'))->assertRedirect(route('panel.login'));
    }

    public function test_valid_totp_is_required_to_complete_login(): void
    {
        $service = app(TwoFactorService::class);
        $validCode = $service->currentCode($this->owner);
        $invalidCode = $validCode === '000000' ? '000001' : '000000';

        $this->post(route('panel.login'), [
            'email' => $this->owner->email,
            'password' => 'secret',
        ]);

        $this->post(route('panel.two-factor.verify'), ['code' => $invalidCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $this->post(route('panel.two-factor.verify'), ['code' => $validCode])
            ->assertRedirect(route('panel.board'));
        $this->assertAuthenticatedAs($this->owner, 'web');
    }

    public function test_recovery_code_is_single_use(): void
    {
        $codes = app(TwoFactorService::class)->recoveryCodes();
        $plainCodes = $codes['plain'];
        $this->owner->forceFill([
            'two_factor_recovery_codes' => $codes['hashed'],
        ])->save();

        $this->post(route('panel.login'), [
            'email' => $this->owner->email,
            'password' => 'secret',
        ]);
        $this->post(route('panel.two-factor.verify'), ['code' => $plainCodes[0]])
            ->assertRedirect(route('panel.board'));
        $this->assertAuthenticatedAs($this->owner, 'web');

        $this->post(route('panel.logout'));
        $this->post(route('panel.login'), [
            'email' => $this->owner->email,
            'password' => 'secret',
        ]);
        $this->post(route('panel.two-factor.verify'), ['code' => $plainCodes[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_owner_can_reset_an_admins_two_factor_configuration(): void
    {
        $admin = $this->unconfirmedAdmin();
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['hashed-code'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.two-factor-reset', $admin))
            ->assertRedirect();

        $admin->refresh();
        $this->assertFalse($admin->hasConfirmedTwoFactor());
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
    }

    public function test_admin_cannot_reset_another_accounts_two_factor_configuration(): void
    {
        $admin = $this->unconfirmedAdmin();
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin, 'web')
            ->post(route('panel.team.two-factor-reset', $this->owner))
            ->assertForbidden();

        $this->assertTrue($this->owner->refresh()->hasConfirmedTwoFactor());
    }

    public function test_inactive_pending_account_cannot_finish_the_challenge(): void
    {
        $code = app(TwoFactorService::class)->currentCode($this->owner);
        $this->post(route('panel.login'), [
            'email' => $this->owner->email,
            'password' => 'secret',
        ]);
        $this->owner->forceFill(['is_active' => false])->save();

        $this->post(route('panel.two-factor.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest('web');
        $this->assertFalse(session()->has('two_factor_pending_user_id'));
    }

    public function test_panel_and_mfa_pages_are_not_browser_cached(): void
    {
        $this->get(route('panel.login'))
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.board'))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    private function unconfirmedAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Unconfirmed Admin',
            'email' => 'unconfirmed-admin@test.local',
            'password' => Hash::make('Strong-Test9!'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}

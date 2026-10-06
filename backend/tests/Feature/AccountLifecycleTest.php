<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\TwoFactorService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\DarakTestCase;

/** Review item 5: password change/reset, recovery codes and MFA enrollment gate. */
class AccountLifecycleTest extends DarakTestCase
{
    private const NEW_PASSWORD = 'New-Strong-Pass9!';

    // --- change password ---------------------------------------------------

    public function test_panel_user_changes_password_and_every_other_session_and_token_dies(): void
    {
        $this->owner->createToken('device:owner-phone');
        DB::table('sessions')->insert([
            'id' => 'other-browser', 'user_id' => $this->owner->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'x', 'payload' => '', 'last_activity' => time(),
        ]);
        $versionBefore = (int) $this->owner->auth_version;

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.account.password'), [
                'current_password' => 'wrong',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasErrors('current_password');

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.account.password'), [
                'current_password' => 'secret',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $owner = $this->owner->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->password));
        $this->assertSame($versionBefore + 1, (int) $owner->auth_version);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertFalse(DB::table('sessions')->where('id', 'other-browser')->exists());

        // The session that made the change carries on.
        $this->get(route('panel.board'))->assertOk();
    }

    public function test_client_password_change_signs_out_other_browsers(): void
    {
        $portal = $this->portalUser();

        $this->actingAs($portal, 'client')
            ->post(route('client.account.password.update'), [
                'current_password' => 'Portal-Pass9!',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasNoErrors();
        $this->get(route('client.home'))->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $portal->refresh()->password));

        // Another browser signed in before the change (it carries version 0).
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->actingAs($portal->refresh(), 'client')
            ->withSession(['client_auth_version' => 0])
            ->get(route('client.home'))
            ->assertRedirect(route('client.login'));
    }

    // --- forgot / reset ----------------------------------------------------

    public function test_forgot_password_is_neutral_and_mails_only_eligible_panel_accounts(): void
    {
        Notification::fake();

        foreach ([$this->owner->email, 'nobody@test.local', 'tech1@test.local'] as $email) {
            $this->post(route('panel.password.email'), ['email' => $email])
                ->assertSessionHas('ok', 'إن كان هذا البريد مسجلًا لدينا فستصلك رسالة بها رابط لتعيين كلمة مرور جديدة خلال دقائق.');
        }

        Notification::assertSentTo($this->owner, ResetPassword::class, function (ResetPassword $notification) {
            $url = $notification->toMail($this->owner)->actionUrl;

            return str_starts_with($url, route('panel.password.reset', $notification->token))
                && ! str_contains($url, 'owner%40test.local') && ! str_contains($url, $this->owner->email);
        });
        Notification::assertNotSentTo($this->technician, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_panel_reset_sets_the_password_and_revokes_sessions_and_tokens(): void
    {
        $this->owner->createToken('device:owner-phone');
        $token = Password::broker('users')->createToken($this->owner);

        $this->post(route('panel.password.update'), [
            'token' => 'not-the-token', 'email' => $this->owner->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('secret', $this->owner->refresh()->password));

        $this->post(route('panel.password.update'), [
            'token' => $token, 'email' => $this->owner->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('panel.login'));

        $owner = $this->owner->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->password));
        $this->assertSame(0, $owner->tokens()->count());
        // The password alone still does not open the panel: MFA is unchanged.
        $this->post(route('panel.login'), ['email' => $owner->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('panel.two-factor.challenge'));
    }

    public function test_client_portal_reset_uses_its_own_broker(): void
    {
        Notification::fake();
        $portal = $this->portalUser();

        $this->post(route('client.password.email'), ['email' => $portal->email])->assertSessionHas('ok');
        $this->assertTrue(DB::table('client_portal_password_reset_tokens')->where('email', $portal->email)->exists());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $portal->email)->exists());

        $token = null;
        Notification::assertSentTo($portal, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return str_starts_with($notification->toMail(ClientPortalUser::first())->actionUrl, route('client.password.reset', $token));
        });

        $this->post(route('client.password.update'), [
            'token' => $token, 'email' => $portal->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('client.login'));
        $this->assertSame(1, (int) $portal->refresh()->auth_version);

        $this->post('/client/login', ['email' => $portal->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('client.home'));
    }

    // --- recovery codes and MFA enrollment ---------------------------------

    public function test_recovery_code_regeneration_needs_password_and_a_live_totp(): void
    {
        $code = app(TwoFactorService::class)->currentCode($this->owner);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.two-factor.recovery-codes'))
            ->assertSessionHasErrors(['password', 'code']);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.two-factor.recovery-codes'), ['password' => 'wrong', 'code' => $code])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.two-factor.recovery-codes'), ['password' => 'secret', 'code' => $code])
            ->assertOk()
            ->assertSee('رموز الاسترداد');
        $this->assertCount(8, $this->owner->refresh()->two_factor_recovery_codes);
    }

    public function test_a_new_mfa_secret_needs_a_recently_confirmed_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'reset-admin@test.local', 'password' => Hash::make('Strong-Test9!'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null,
        ]);

        // A session whose password check is older than ten minutes.
        $this->actingAs($admin, 'web')
            ->withSession(['auth.password_confirmed_at' => time() - 601])
            ->get(route('panel.two-factor.setup'))
            ->assertRedirect(route('panel.password.confirm'));
        $this->assertNull($admin->refresh()->two_factor_secret, 'no secret is generated before the check');

        $this->post(route('panel.password.confirm'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('panel.password.confirm'), ['password' => 'Strong-Test9!'])
            ->assertRedirect(route('panel.two-factor.setup'));

        $this->get(route('panel.two-factor.setup'))->assertOk()->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_reset_clears_a_password_lockout_for_that_account(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'wrong']);
        }

        app(AccountSecurity::class)->setPanelPassword($this->owner, self::NEW_PASSWORD, null, 'reset');

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('panel.two-factor.challenge'));
    }

    private function portalUser(): ClientPortalUser
    {
        return ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Portal', 'email' => 'portal@test.local',
            'password' => Hash::make('Portal-Pass9!'), 'is_active' => true,
        ]);
    }
}

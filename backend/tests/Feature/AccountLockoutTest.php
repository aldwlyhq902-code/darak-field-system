<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\NotificationMessage;
use App\Services\TwoFactorService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Review findings M2 and the password-login hardening: per-account lockouts on
 * top of per-IP throttles, TOTP replay protection and constant-time failures.
 */
class AccountLockoutTest extends DarakTestCase
{
    // --- TOTP replay -------------------------------------------------------

    public function test_a_totp_code_cannot_be_replayed_on_the_panel(): void
    {
        $code = app(TwoFactorService::class)->currentCode($this->owner);

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret']);
        $this->post(route('panel.two-factor.verify'), ['code' => $code])->assertRedirect(route('panel.board'));
        $this->post(route('panel.logout'));

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret']);
        $this->post(route('panel.two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_a_totp_code_cannot_be_replayed_to_mint_a_second_api_token(): void
    {
        $code = app(TwoFactorService::class)->currentCode($this->owner);
        $payload = ['email' => $this->owner->email, 'password' => 'secret', 'mfa_code' => $code];

        $this->postJson('/api/v1/auth/login', $payload + ['device_uuid' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/auth/login', $payload + ['device_uuid' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
    }

    // --- MFA lockout -------------------------------------------------------

    public function test_repeated_wrong_codes_lock_the_panel_second_factor_and_tell_the_owner(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $valid = app(TwoFactorService::class)->currentCode($this->owner);
        $wrong = $valid === '000000' ? '111111' : '000000';

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('panel.two-factor.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        }

        // The pending login is gone: more guesses need the password again.
        $this->assertFalse(session()->has('two_factor_pending_user_id'));

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret']);
        $this->post(route('panel.two-factor.verify'), ['code' => $valid])->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $alert = NotificationMessage::where('type', NotificationMessage::TYPE_SECURITY_ALERT)->sole();
        $this->assertSame($this->owner->id, $alert->user_id);
        $this->assertSame('mfa_lockout', $alert->context['reason']);
    }

    public function test_repeated_wrong_codes_lock_the_api_second_factor(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $payload = ['email' => $this->owner->email, 'password' => 'secret', 'device_uuid' => (string) Str::uuid()];

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', $payload + ['mfa_code' => '000000'])
                ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
        }
        $this->postJson('/api/v1/auth/login', $payload + ['mfa_code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ACCOUNT_TEMPORARILY_LOCKED');

        $this->postJson('/api/v1/auth/login', $payload + [
            'mfa_code' => app(TwoFactorService::class)->currentCode($this->owner),
        ])->assertStatus(429)->assertJsonPath('code', 'ACCOUNT_TEMPORARILY_LOCKED');
    }

    public function test_second_factor_throttle_is_keyed_by_account_and_ip(): void
    {
        // Lockout out of the way: this asserts the ROUTE throttle on its own.
        config(['darak.auth.mfa_max_attempts' => 50]);
        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('panel.two-factor.verify'), ['code' => 'ABCDE-FGHIJ'])->assertSessionHasErrors('code');
        }

        $this->post(route('panel.two-factor.verify'), ['code' => 'ABCDE-FGHIJ'])->assertStatus(429);
    }

    // --- password lockout --------------------------------------------------

    public function test_panel_password_lockout_engages_and_ignores_a_correct_password_meanwhile(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post(route('panel.login'), ['email' => $this->owner->email, 'password' => 'secret'])
            ->assertSessionHasErrors(['email' => 'محاولات دخول كثيرة لهذا الحساب. حاول مرة أخرى بعد 15 دقيقة.']);
        $this->assertFalse(session()->has('two_factor_pending_user_id'));

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SECURITY_ALERT)
            ->where('user_id', $this->owner->id)->count());
    }

    public function test_unknown_email_locks_exactly_like_a_real_one(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('panel.login'), ['email' => 'nobody@test.local', 'password' => 'wrong']);
        }

        $this->post(route('panel.login'), ['email' => 'nobody@test.local', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'محاولات دخول كثيرة لهذا الحساب. حاول مرة أخرى بعد 15 دقيقة.']);
        $this->assertSame(0, NotificationMessage::where('type', NotificationMessage::TYPE_SECURITY_ALERT)->count());
    }

    public function test_api_password_lockout_returns_a_stable_code(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $payload = ['email' => 'tech1@test.local', 'device_uuid' => $this->device->device_uuid];

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', $payload + ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', $payload + ['password' => 'wrong'])
            ->assertStatus(429)->assertJsonPath('code', 'ACCOUNT_TEMPORARILY_LOCKED');
        $this->postJson('/api/v1/auth/login', $payload + ['password' => 'secret'])
            ->assertStatus(429)->assertJsonPath('code', 'ACCOUNT_TEMPORARILY_LOCKED');
    }

    public function test_client_portal_password_lockout(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Client', 'email' => 'portal@test.local',
            'password' => Hash::make('Portal-Pass9!'), 'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/client/login', ['email' => 'portal@test.local', 'password' => 'wrong']);
        }

        $this->post('/client/login', ['email' => 'portal@test.local', 'password' => 'Portal-Pass9!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('client');
        $this->assertSame('client_password_lockout', NotificationMessage::where('type', NotificationMessage::TYPE_SECURITY_ALERT)
            ->sole()->context['reason']);
    }

    public function test_a_successful_login_resets_the_failure_counter(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $payload = ['email' => 'tech1@test.local', 'device_uuid' => $this->device->device_uuid];

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', $payload + ['password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', $payload + ['password' => 'secret'])->assertOk();
        $this->postJson('/api/v1/auth/login', $payload + ['password' => 'wrong'])->assertUnprocessable();
    }

    public function test_unknown_email_costs_as_long_as_a_wrong_password(): void
    {
        // Before: an unknown email returned before any hash was computed, so
        // response time alone told an attacker which accounts exist.
        $started = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@test.local', 'password' => 'whatever', 'device_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Invalid credentials.');
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertGreaterThanOrEqual(380, $elapsedMs);
    }
}

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Authentication and API hardening that has to be wired at boot: named rate
 * limiters (keyed by account as well as by IP) and token validity rules.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Password forms: the IP bucket stops one noisy client, the account
        // bucket stops many quiet ones hammering the same email from one place.
        // The per-account LOCKOUT (AccountLockout) covers rotating addresses.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->path().'|ip|'.$request->ip()),
            Limit::perMinute(5)->by($request->path().'|account|'.hash('sha256', mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip())),
        ]);

        // The second factor is keyed by the pending ACCOUNT and IP: per-IP alone
        // let one password-holder spread guesses over many addresses.
        RateLimiter::for('mfa', fn (Request $request) => [
            Limit::perMinute(10)->by('mfa|ip|'.$request->ip()),
            Limit::perMinute(5)->by('mfa|account|'.($request->hasSession()
                ? (string) ($request->session()->get('two_factor_pending_user_id') ?? $request->user()?->getAuthIdentifier())
                : '').'|'.$request->ip()),
        ]);
    }
}

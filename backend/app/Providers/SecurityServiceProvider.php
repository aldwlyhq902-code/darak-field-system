<?php

namespace App\Providers;

use App\Models\ClientPortalUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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

        // Reset-link requests: few per IP, fewer per address. The broker adds its
        // own one-per-minute rule per account; all three answer neutrally.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('reset|ip|'.$request->ip()),
            Limit::perMinutes(10, 3)->by('reset|email|'.hash('sha256', mb_strtolower(trim((string) $request->input('email'))))),
        ]);

        // The reset link carries the token only — never the email address, which
        // would otherwise sit in browser history and proxy logs.
        ResetPassword::createUrlUsing(fn ($notifiable, string $token): string => route(
            $notifiable instanceof ClientPortalUser ? 'client.password.reset' : 'panel.password.reset',
            ['token' => $token],
        ));
        ResetPassword::toMailUsing(fn ($notifiable, string $token): MailMessage => (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور — '.config('app.name'))
            ->greeting('مرحبًا '.$notifiable->name)
            ->line('وصلنا طلب لإعادة تعيين كلمة مرور حسابك.')
            ->action('تعيين كلمة مرور جديدة', route(
                $notifiable instanceof ClientPortalUser ? 'client.password.reset' : 'panel.password.reset',
                ['token' => $token],
            ))
            ->line('ينتهي الرابط خلال 60 دقيقة. إن لم تطلب ذلك فتجاهل هذه الرسالة؛ كلمة مرورك الحالية لم تتغير.'));

        // Idle expiry on top of Sanctum's absolute 30-day lifetime. A token on a
        // phone left in a drawer for two weeks is more likely lost than in use;
        // the app answers the 401 with a fresh login and keeps its queue.
        // Evaluated BEFORE Sanctum stamps last_used_at for this request.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $idleDays = (int) config('darak.auth.token_idle_days', 14);

            if (! $isValid || $idleDays <= 0) {
                return $isValid;
            }

            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $lastActivity === null || $lastActivity->gt(now()->subDays($idleDays));
        });
    }
}

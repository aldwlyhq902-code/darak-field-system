<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientPortalUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('client')->user();

        if ($user === null || ! $user->is_active || ! $user->client?->is_active) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('client.login')->withErrors([
                'email' => 'حساب العميل غير متاح. تواصل مع دارك.',
            ]);
        }

        // A password change or reset bumps auth_version: every session that
        // signed in before it — including a remember-me revival — ends here.
        if ((int) $request->session()->get('client_auth_version', 0) !== (int) $user->auth_version) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('client.login')->withErrors([
                'email' => 'تغيّرت كلمة مرور الحساب. سجّل الدخول مرة أخرى.',
            ]);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->hasConfirmedTwoFactor()) {
            return redirect()->route('panel.two-factor.setup');
        }

        $confirmedUserId = (int) $request->session()->get('panel_mfa_user_id', 0);
        $confirmedAuthVersion = (int) $request->session()->get('panel_auth_version', 0);
        if ($confirmedUserId !== (int) $user->getAuthIdentifier()
            || $confirmedAuthVersion !== (int) $user->auth_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('panel.login')
                ->with('err', 'انتهت صلاحية جلسة التحقق. سجّل الدخول مرة أخرى.');
        }

        return $next($request);
    }
}

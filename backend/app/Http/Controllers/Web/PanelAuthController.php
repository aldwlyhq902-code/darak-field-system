<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session auth for the panel, separate from the device tokens the app uses.
 * Technicians have no panel access — their surface is the phone.
 */
class PanelAuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('panel.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        $user = Auth::guard('web')->user();

        if ($user->isTechnician() || ! $user->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'هذه اللوحة للمشرف والإدارة. الفنيون يستخدمون التطبيق.',
            ]);
        }

        $remember = $request->boolean('remember');
        $request->session()->regenerate();

        if (! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('panel.two-factor.setup');
        }

        // A password alone never creates an authorised panel session once MFA is
        // enabled. Keep only the pending user id until the second factor passes.
        Auth::guard('web')->logout();
        $request->session()->put([
            'two_factor_pending_user_id' => $user->id,
            'two_factor_remember' => $remember,
        ]);

        return redirect()->route('panel.two-factor.challenge');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }
}

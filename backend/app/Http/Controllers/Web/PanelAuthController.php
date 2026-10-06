<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PasswordLogin;
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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PasswordLogin $passwords,
    ) {}

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

        $result = $this->passwords->attempt('web', User::class, $credentials['email'], $credentials['password']);

        if ($result['locked_for'] > 0) {
            throw ValidationException::withMessages(['email' => PasswordLogin::lockedMessage($result['locked_for'])]);
        }

        /** @var User|null $user */
        $user = $result['user'];

        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        if ($user->isTechnician() || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'هذه اللوحة للمشرف والإدارة. الفنيون يستخدمون التطبيق.',
            ]);
        }

        $remember = $request->boolean('remember');

        if (! $user->hasConfirmedTwoFactor()) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();

            return redirect()->route('panel.two-factor.setup');
        }

        // A password alone never creates an authorised panel session once MFA is
        // enabled. Keep only the pending user id until the second factor passes.
        $request->session()->regenerate();
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

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AccountSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self-service account security for panel (and sales) users, plus the
 * password re-confirmation that gates showing a new MFA secret.
 */
class AccountSecurityController extends Controller
{
    public const MESSAGES = [
        'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
        'password.current_password' => 'كلمة المرور غير صحيحة.',
        'password.different' => 'اختر كلمة مرور مختلفة عن الحالية.',
        'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        'password.min' => 'كلمة المرور 12 حرفًا على الأقل.',
        'password.letters' => 'يجب أن تحتوي كلمة المرور على حروف.',
        'password.mixed' => 'يجب أن تحتوي كلمة المرور على حروف كبيرة وصغيرة.',
        'password.numbers' => 'يجب أن تحتوي كلمة المرور على أرقام.',
        'password.symbols' => 'يجب أن تحتوي كلمة المرور على رموز.',
    ];

    public function __construct(private readonly AccountSecurity $security) {}

    public function show(): View
    {
        return view('panel.account-security');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [
                'required', 'confirmed', 'different:current_password',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ], self::MESSAGES);

        $user = $request->user();
        $this->security->setPanelPassword($user, $data['password'], $request->session()->getId(), 'changed');

        // This session survives — it just proved the old AND set the new
        // password — but it must carry the new auth version like a fresh login.
        $request->session()->regenerate();
        $request->session()->put([
            'panel_mfa_user_id' => $user->getAuthIdentifier(),
            'panel_auth_version' => (int) $user->auth_version,
        ]);

        return back()->with('ok', 'تم تغيير كلمة المرور. أُنهيت جلساتك الأخرى ورموز التطبيق.');
    }

    public function showConfirmPassword(): View
    {
        return view('panel.confirm-password');
    }

    /** Laravel's password.confirm contract: stamp the session, go back. */
    public function confirmPassword(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']], self::MESSAGES);

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('panel.two-factor.setup'));
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AccountSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Client-portal password change. Other browsers are signed out by it. */
class ClientAccountController extends Controller
{
    public function __construct(private readonly AccountSecurity $security) {}

    public function editPassword(): View
    {
        return view('client.account-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:client'],
            'password' => [
                'required', 'confirmed', 'different:current_password',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ], AccountSecurityController::MESSAGES);

        $user = $request->user('client');
        $this->security->setClientPassword($user, $data['password'], 'changed');

        $request->session()->regenerate();
        $request->session()->put('client_auth_version', (int) $user->auth_version);

        return back()->with('ok', 'تم تغيير كلمة المرور. سُجّل خروج المتصفحات الأخرى.');
    }
}

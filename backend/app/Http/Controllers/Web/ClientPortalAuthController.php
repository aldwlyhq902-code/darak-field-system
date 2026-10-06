<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ClientPortalUser;
use App\Services\PasswordLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientPortalAuthController extends Controller
{
    public function __construct(private readonly PasswordLogin $passwords) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::guard('client')->check()) {
            return redirect()->route('client.home');
        }

        return view('client.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->passwords->attempt('client', ClientPortalUser::class, $credentials['email'], $credentials['password']);

        if ($result['locked_for'] > 0) {
            throw ValidationException::withMessages(['email' => PasswordLogin::lockedMessage($result['locked_for'])]);
        }

        /** @var ClientPortalUser|null $user */
        $user = $result['user'];

        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        if (! $user->is_active || ! $user->client?->is_active) {
            throw ValidationException::withMessages(['email' => 'حساب العميل غير متاح.']);
        }

        Auth::guard('client')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('client.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('client.login');
    }
}

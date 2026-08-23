<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientPortalAuthController extends Controller
{
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

        if (! Auth::guard('client')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        $user = Auth::guard('client')->user();
        if (! $user->is_active || ! $user->client?->is_active) {
            Auth::guard('client')->logout();
            throw ValidationException::withMessages(['email' => 'حساب العميل غير متاح.']);
        }

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

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isTechnician(), 403);

        if ($user->hasConfirmedTwoFactor()) {
            return redirect()->route('panel.board');
        }

        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret()])->save();
        }

        return view('panel.two-factor-setup', [
            'secret' => $user->two_factor_secret,
            'provisioningUri' => $this->twoFactor->provisioningUri($user),
        ]);
    }

    public function confirm(Request $request): View
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $request->user();
        abort_if($user->isTechnician(), 403);

        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.']);
        }

        $codes = $this->twoFactor->recoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes['hashed'],
        ])->save();

        $request->session()->regenerate();
        $this->audit->record('auth.two_factor_enabled', $user, null, ['recovery_codes' => count($codes['plain'])], $user->id);
        $this->audit->record('panel.login', $user, null, ['role' => $user->role, 'mfa' => true], $user->id);

        return view('panel.two-factor-recovery', ['codes' => $codes['plain']]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('two_factor_pending_user_id')) {
            return redirect()->route('panel.login');
        }

        return view('panel.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = User::find($request->session()->get('two_factor_pending_user_id'));

        if ($user === null || ! $user->is_active || $user->isTechnician() || ! $user->hasConfirmedTwoFactor()) {
            $request->session()->forget(['two_factor_pending_user_id', 'two_factor_remember']);

            throw ValidationException::withMessages(['code' => 'انتهت جلسة التحقق. سجّل الدخول مرة أخرى.']);
        }

        $valid = $this->twoFactor->verify($user, $data['code'])
            || $this->twoFactor->consumeRecoveryCode($user, $data['code']);

        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'رمز التحقق أو الاسترداد غير صحيح.']);
        }

        $remember = (bool) $request->session()->pull('two_factor_remember', false);
        $request->session()->forget('two_factor_pending_user_id');
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $this->audit->record('panel.login', $user, null, ['role' => $user->role, 'mfa' => true], $user->id);

        return redirect()->intended(route('panel.board'));
    }

    public function regenerateRecoveryCodes(Request $request): View
    {
        $codes = $this->twoFactor->recoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();

        $this->audit->record('auth.recovery_codes_regenerated', $request->user(), null, [], $request->user()->id);

        return view('panel.two-factor-recovery', ['codes' => $codes['plain']]);
    }

    public function reset(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        abort_if($user->isTechnician(), 422, 'MFA applies to panel accounts only.');

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        $this->audit->record('auth.two_factor_reset', $user, null, [], $request->user()->id);

        return back()->with('ok', 'أُعيد ضبط التحقق بخطوتين. سيُطلب إعداده عند الدخول التالي.');
    }
}

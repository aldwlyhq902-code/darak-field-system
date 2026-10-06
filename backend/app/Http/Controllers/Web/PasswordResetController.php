<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ClientPortalUser;
use App\Models\User;
use App\Services\AccountSecurity;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "Forgot password" for panel accounts and client-portal accounts, each on its
 * own broker and token table.
 *
 * Every response is the same whether or not the address has an account, and
 * the request is padded to a fixed minimum duration, so the form cannot be used
 * to discover who is a customer or an employee. Technicians are not served
 * here: their password is managed by the owner and their phone is their door.
 */
class PasswordResetController extends Controller
{
    private const NEUTRAL_SENT = 'إن كان هذا البريد مسجلًا لدينا فستصلك رسالة بها رابط لتعيين كلمة مرور جديدة خلال دقائق.';

    private const INVALID_LINK = 'رابط إعادة التعيين غير صالح أو انتهت صلاحيته. اطلب رابطًا جديدًا.';

    private const AUDIENCES = [
        'panel' => [
            'broker' => 'users',
            'layout' => 'layouts.panel',
            'login' => 'panel.login',
            'email' => 'panel.password.email',
            'update' => 'panel.password.update',
            'request' => 'panel.password.request',
        ],
        'client' => [
            'broker' => 'client_portal_users',
            'layout' => 'layouts.client',
            'login' => 'client.login',
            'email' => 'client.password.email',
            'update' => 'client.password.update',
            'request' => 'client.password.request',
        ],
    ];

    public function __construct(private readonly AccountSecurity $security) {}

    public function request(Request $request): View
    {
        return view('auth.forgot-password', $this->viewData($request));
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $audience = $this->audience($request);

        (new Timebox)->call(function () use ($audience, $data): void {
            if ($this->eligibleAccount($audience, $data['email']) === null) {
                return;
            }

            // RESET_THROTTLED (one link per minute per address) is swallowed:
            // reporting it would confirm the address exists.
            PasswordBroker::broker(self::AUDIENCES[$audience]['broker'])->sendResetLink(['email' => $data['email']]);
        }, 600_000);

        return back()->with('ok', self::NEUTRAL_SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', $this->viewData($request) + ['token' => $token]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ], AccountSecurityController::MESSAGES);
        $audience = $this->audience($request);

        $status = PasswordBroker::broker(self::AUDIENCES[$audience]['broker'])->reset(
            $data,
            function (CanResetPassword $account, string $password) use ($audience): void {
                if ($this->eligibleAccount($audience, $account->getEmailForPasswordReset()) === null) {
                    return;
                }

                $account instanceof ClientPortalUser
                    ? $this->security->setClientPassword($account, $password, 'reset')
                    : $this->security->setPanelPassword($account, $password, null, 'reset');
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => self::INVALID_LINK]);
        }

        return redirect()->route(self::AUDIENCES[$audience]['login'])
            ->with('ok', 'تم تعيين كلمة المرور الجديدة. سجّل الدخول بها الآن.');
    }

    private function eligibleAccount(string $audience, string $email): User|ClientPortalUser|null
    {
        if ($audience === 'client') {
            $account = ClientPortalUser::query()->where('email', $email)->first();

            return $account !== null && $account->is_active && $account->client?->is_active ? $account : null;
        }

        $account = User::query()->where('email', $email)->first();

        return $account !== null && $account->is_active && ! $account->isTechnician() ? $account : null;
    }

    private function audience(Request $request): string
    {
        return $request->routeIs('client.*') ? 'client' : 'panel';
    }

    /** @return array<string, string> */
    private function viewData(Request $request): array
    {
        return self::AUDIENCES[$this->audience($request)];
    }
}

<?php

namespace App\Services;

use App\Models\ClientPortalUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password changes and resets, with the same consequences everywhere: every
 * OTHER session and every API token of the account stops working. A password
 * change is usually a reaction to "someone else may know it".
 */
class AccountSecurity
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountLockout $lockout,
    ) {}

    /**
     * @param  string|null  $keepSessionId  the session that made the change (it
     *                                      re-proves itself by storing the new auth_version); null on reset
     */
    public function setPanelPassword(User $user, string $password, ?string $keepSessionId, string $reason): void
    {
        DB::transaction(function () use ($user, $password, $keepSessionId): void {
            $user->forceFill([
                'password' => $password,
                'auth_version' => (int) $user->auth_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            // Panel sessions also die through the auth_version check; deleting
            // the rows covers the database driver immediately.
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();

            $user->tokens()->delete();
        });

        $this->clearPasswordLockouts($user->email, ['web', 'api']);
        $this->audit->record('auth.password_'.$reason, $user, null, ['sessions_ended' => true, 'api_tokens_revoked' => true], $user->id);
    }

    public function setClientPassword(ClientPortalUser $user, string $password, string $reason): void
    {
        // Client sessions carry client_auth_version; bumping it logs out every
        // other browser on its next request (EnsureClientPortalUserIsActive).
        $user->forceFill([
            'password' => $password,
            'auth_version' => (int) $user->auth_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        $this->clearPasswordLockouts($user->email, ['client']);
        $this->audit->record('client_portal.password_'.$reason, $user, null, [
            'client_portal_user_id' => $user->id,
            'sessions_ended' => true,
        ]);
    }

    /** @param array<int, string> $guards */
    private function clearPasswordLockouts(string $email, array $guards): void
    {
        foreach ($guards as $guard) {
            $this->lockout->clear($this->lockout->passwordScope($guard), AccountLockout::normaliseEmail($email));
        }
    }
}

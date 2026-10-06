<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-account failure counters with a temporary lockout.
 *
 * The route throttles are keyed by IP, which a distributed guesser simply
 * rotates. These counters are keyed by the ACCOUNT being attacked: the submitted
 * email for passwords (so an unknown address locks exactly like a real one and
 * the response never reveals which accounts exist) and the user id for second
 * factors (the password has already been proven at that point).
 *
 * Stored in the application cache, which is the database store in production —
 * the same durability the existing route throttles rely on.
 */
class AccountLockout
{
    public const SCOPE_MFA = 'mfa';

    /** Seconds until the lock lifts, or 0 when the account is not locked. */
    public function lockedFor(string $scope, string $identity): int
    {
        $until = (int) Cache::get($this->key($scope, $identity).':until', 0);

        return max(0, $until - time());
    }

    /**
     * Count one failure. Returns true exactly when THIS failure engaged the lock,
     * so the caller notifies once per lockout rather than once per attempt.
     */
    public function recordFailure(string $scope, string $identity, int $maxAttempts, int $lockoutMinutes): bool
    {
        $key = $this->key($scope, $identity);
        $window = now()->addMinutes(max(1, $lockoutMinutes));

        Cache::add($key.':count', 0, $window);
        $failures = (int) Cache::increment($key.':count');

        if ($failures < max(1, $maxAttempts)) {
            return false;
        }

        Cache::put($key.':until', time() + max(1, $lockoutMinutes) * 60, $window);
        Cache::forget($key.':count');

        return true;
    }

    public function clear(string $scope, string $identity): void
    {
        $key = $this->key($scope, $identity);
        Cache::forget($key.':count');
        Cache::forget($key.':until');
    }

    public function passwordScope(string $guard): string
    {
        return 'password:'.$guard;
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function key(string $scope, string $identity): string
    {
        // Hashed so raw email addresses never sit in the cache table.
        return 'account-lockout:'.$scope.':'.hash('sha256', $identity);
    }
}

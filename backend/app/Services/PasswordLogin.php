<?php

namespace App\Services;

use App\Models\ClientPortalUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;

/**
 * One password check for the panel, the client portal and the device API.
 *
 * Two leaks closed here:
 *  - Timing. An unknown email used to return before any hash was computed, so
 *    response time alone revealed which addresses had accounts. Unknown emails
 *    now verify against a dummy hash and every failure is padded to the same
 *    minimum duration.
 *  - Unlimited guessing. Per-IP throttles do not stop a guesser that rotates
 *    addresses; failures are also counted against the submitted email.
 */
class PasswordLogin
{
    private const TIMEBOX_MICROSECONDS = 400_000;

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly AccountLockout $lockout,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  class-string<User|ClientPortalUser>  $model
     * @return array{user: User|ClientPortalUser|null, locked_for: int}
     */
    public function attempt(string $guard, string $model, string $email, string $password): array
    {
        $identity = AccountLockout::normaliseEmail($email);
        $scope = $this->lockout->passwordScope($guard);

        $lockedFor = $this->lockout->lockedFor($scope, $identity);
        if ($lockedFor > 0) {
            return ['user' => null, 'locked_for' => $lockedFor];
        }

        /** @var User|ClientPortalUser|null $user */
        $user = (new Timebox)->call(function (Timebox $timebox) use ($model, $email, $password) {
            $candidate = $model::query()->where('email', trim($email))->first();
            $valid = Hash::check($password, $candidate?->getAuthPassword() ?: self::dummyHash());

            if ($candidate !== null && $valid) {
                $timebox->returnEarly();

                return $candidate;
            }

            return null;
        }, self::TIMEBOX_MICROSECONDS);

        if ($user !== null) {
            $this->lockout->clear($scope, $identity);

            return ['user' => $user, 'locked_for' => 0];
        }

        $minutes = (int) config('darak.auth.password_lockout_minutes', 15);
        $locked = $this->lockout->recordFailure(
            $scope,
            $identity,
            (int) config('darak.auth.password_max_attempts', 5),
            $minutes,
        );

        if ($locked) {
            $this->notifyLockout($model, trim($email), $minutes);

            return ['user' => null, 'locked_for' => $minutes * 60];
        }

        return ['user' => null, 'locked_for' => 0];
    }

    /** Arabic, user-facing. Identical for existing and unknown accounts. */
    public static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return "محاولات دخول كثيرة لهذا الحساب. حاول مرة أخرى بعد {$minutes} دقيقة.";
    }

    private function notifyLockout(string $model, string $email, int $minutes): void
    {
        // Only real accounts produce an inbox entry; the HTTP response above is
        // the same either way.
        $account = $model::query()->where('email', $email)->first();
        if ($account === null) {
            return;
        }

        $attempts = (int) config('darak.auth.password_max_attempts', 5);
        $key = 'security.password_lockout:'.class_basename($model).':'.$account->getKey().':'.time();

        if ($account instanceof User) {
            $this->notifications->securityAlert(
                $key,
                "قُفل الدخول بكلمة المرور مؤقتًا ({$minutes} دقيقة) للحساب {$account->name} ({$account->email}) بعد {$attempts} محاولات فاشلة.",
                $account->id,
                ['reason' => 'password_lockout', 'operating_branch_id' => $account->operating_branch_id],
            );

            return;
        }

        /** @var Model&ClientPortalUser $account */
        $account->loadMissing('client');
        $this->notifications->securityAlert(
            $key,
            "قُفل دخول بوابة العميل مؤقتًا ({$minutes} دقيقة) للحساب {$account->email} — {$account->client?->name} بعد {$attempts} محاولات فاشلة.",
            null,
            ['reason' => 'client_password_lockout', 'operating_branch_id' => $account->client?->operating_branch_id],
        );
    }

    private static function dummyHash(): string
    {
        // Same algorithm and cost as real hashes, computed once per process.
        return self::$dummyHash ??= Hash::make('darak-timing-equaliser');
    }
}

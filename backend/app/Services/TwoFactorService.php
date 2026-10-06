<?php

namespace App\Services;

use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

class TwoFactorService
{
    public function __construct(
        private readonly AccountLockout $lockout,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The only entry point for a second-factor ATTEMPT (panel challenge, API
     * login, recovery-code regeneration). Failures count against the account,
     * not the IP: the password has already been proven, so repeated wrong codes
     * mean either a confused owner or a stolen password — both worth stopping
     * and both worth telling the owner about.
     *
     * @return array{valid: bool, locked_for: int}
     */
    public function attempt(User $user, string $code, bool $allowRecoveryCode = true): array
    {
        $identity = (string) $user->getKey();
        $lockedFor = $this->lockout->lockedFor(AccountLockout::SCOPE_MFA, $identity);

        if ($lockedFor > 0) {
            return ['valid' => false, 'locked_for' => $lockedFor];
        }

        $valid = $code !== '' && (
            $this->verify($user, $code)
            || ($allowRecoveryCode && $this->consumeRecoveryCode($user, $code))
        );

        if ($valid) {
            $this->lockout->clear(AccountLockout::SCOPE_MFA, $identity);

            return ['valid' => true, 'locked_for' => 0];
        }

        $minutes = (int) config('darak.auth.mfa_lockout_minutes', 15);
        $attempts = (int) config('darak.auth.mfa_max_attempts', 5);

        if (! $this->lockout->recordFailure(AccountLockout::SCOPE_MFA, $identity, $attempts, $minutes)) {
            return ['valid' => false, 'locked_for' => 0];
        }

        $this->notifications->securityAlert(
            'security.mfa_lockout:'.$user->id.':'.time(),
            "قُفل التحقق بخطوتين مؤقتًا ({$minutes} دقيقة) للحساب {$user->name} ({$user->email}) بعد {$attempts} رموز خاطئة. "
                .'كلمة المرور أُدخلت صحيحة — إن لم يكن صاحب الحساب فغيّرها فورًا.',
            $user->id,
            ['reason' => 'mfa_lockout', 'operating_branch_id' => $user->operating_branch_id],
        );

        return ['valid' => false, 'locked_for' => $minutes * 60];
    }

    /** Arabic, user-facing. */
    public static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return "أُوقف التحقق بخطوتين مؤقتًا بعد محاولات خاطئة متكررة. حاول بعد {$minutes} دقيقة.";
    }

    public function generateSecret(): string
    {
        return TOTP::generate(secretSize: 20)->getSecret();
    }

    public function provisioningUri(User $user): string
    {
        return $this->totp($user)->getProvisioningUri();
    }

    public function provisioningQrDataUri(User $user): string
    {
        return Builder::create()
            ->writer(new SvgWriter)
            ->data($this->provisioningUri($user))
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(320)
            ->margin(16)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->foregroundColor(new Color(5, 47, 44))
            ->backgroundColor(new Color(255, 255, 255))
            ->build()
            ->getDataUri();
    }

    public function currentCode(User $user): string
    {
        return $this->totp($user)->now();
    }

    /**
     * Verify AND consume a TOTP code.
     *
     * A code stays valid for its whole 30-second step (plus 15 s leeway), so a
     * code read over a shoulder or relayed by a phishing page could open a second
     * session inside that window. The step a code belongs to is recorded per user
     * and only a strictly later step is ever accepted again. The compare-and-set
     * runs in the database, so two concurrent submissions of one code cannot
     * both succeed.
     */
    public function verify(User $user, string $code): bool
    {
        $normalised = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($normalised) !== 6) {
            return false;
        }

        $step = $this->matchingStep($user, $normalised);

        if ($step === null) {
            return false;
        }

        $consumed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query
                ->whereNull('two_factor_last_used_step')
                ->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);

        if ($consumed === 1) {
            $user->forceFill(['two_factor_last_used_step' => $step])->syncOriginalAttribute('two_factor_last_used_step');
        }

        return $consumed === 1;
    }

    /** The time step whose code matches, within the accepted leeway, or null. */
    private function matchingStep(User $user, string $code): ?int
    {
        $totp = $this->totp($user);
        $period = $totp->getPeriod();
        $now = time();
        $steps = array_unique([
            intdiv($now - 15, $period),
            intdiv($now, $period),
            intdiv($now + 15, $period),
        ]);

        foreach ($steps as $step) {
            if (hash_equals($totp->at($step * $period), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** @return array{plain: array<int, string>, hashed: array<int, string>} */
    public function recoveryCodes(int $count = 8): array
    {
        $plain = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return [
            'plain' => $plain,
            // The array is encrypted at rest by the User cast. A keyed digest
            // also prevents recovery-code disclosure while avoiding eight
            // sequential password hashes during MFA enrollment.
            'hashed' => array_map(fn (string $code) => $this->recoveryDigest($code), $plain),
        ];
    }

    public function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $candidate = $this->normaliseRecoveryCode($candidate);

        return DB::transaction(function () use ($user, $candidate): bool {
            // Recovery codes are single-use credentials. Locking the account row
            // makes verification and consumption one atomic operation.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $codes = $locked->two_factor_recovery_codes ?? [];

            foreach ($codes as $index => $hash) {
                $valid = str_starts_with($hash, 'hmac:')
                    ? hash_equals($hash, $this->recoveryDigest($candidate))
                    // Preserve codes generated before the keyed-digest format.
                    : Hash::check($candidate, $hash);

                if ($valid) {
                    unset($codes[$index]);
                    $codes = array_values($codes);
                    $locked->forceFill(['two_factor_recovery_codes' => $codes])->save();
                    $user->forceFill(['two_factor_recovery_codes' => $codes]);

                    return true;
                }
            }

            return false;
        });
    }

    private function totp(User $user): TOTP
    {
        $secret = (string) $user->two_factor_secret;

        if ($secret === '') {
            throw new \RuntimeException('Two-factor authentication is not configured for this account.');
        }

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($user->email);
        $totp->setIssuer((string) config('app.name', 'Darak'));

        return $totp;
    }

    private function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }

    private function recoveryDigest(string $code): string
    {
        return 'hmac:'.hash_hmac(
            'sha256',
            $this->normaliseRecoveryCode($code),
            (string) config('app.key'),
        );
    }
}

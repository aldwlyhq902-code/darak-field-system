<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

class TwoFactorService
{
    public function generateSecret(): string
    {
        return TOTP::generate(secretSize: 20)->getSecret();
    }

    public function provisioningUri(User $user): string
    {
        return $this->totp($user)->getProvisioningUri();
    }

    public function currentCode(User $user): string
    {
        return $this->totp($user)->now();
    }

    public function verify(User $user, string $code): bool
    {
        $normalised = preg_replace('/\D/', '', $code) ?? '';

        return strlen($normalised) === 6 && $this->totp($user)->verify($normalised, leeway: 15);
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
            'hashed' => array_map(fn (string $code) => Hash::make($this->normaliseRecoveryCode($code)), $plain),
        ];
    }

    public function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $candidate = $this->normaliseRecoveryCode($candidate);
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check($candidate, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
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
}

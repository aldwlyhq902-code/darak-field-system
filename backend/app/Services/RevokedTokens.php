<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deletes API tokens while remembering why, so the phone holding one hears
 * "revoked" (and wipes itself) instead of "signed out" (and keeps the data).
 *
 * Only revocation and deactivation go through here. A password change or an
 * MFA reset still deletes tokens silently: those are a fresh sign-in, not a wipe.
 */
class RevokedTokens
{
    public const DEVICE_REVOKED = 'DEVICE_REVOKED';

    public const USER_INACTIVE = 'USER_INACTIVE';

    /**
     * @param  Builder<PersonalAccessToken>|Relation  $tokens
     */
    public function revoke(Builder|Relation $tokens, string $code): void
    {
        $now = CarbonImmutable::now();
        // A tombstone has to outlive every token it stands for. Sanctum rejects a
        // token past this age anyway, so nothing older is worth answering for.
        $expiresAt = $now->addMinutes(max(1, (int) config('sanctum.expiration', 43200)));

        DB::transaction(function () use ($tokens, $code, $now, $expiresAt): void {
            $rows = (clone $tokens)->get(['id', 'token', 'tokenable_id'])
                ->map(fn ($token) => [
                    'token_hash' => $token->token,
                    'code' => $code,
                    'user_id' => $token->tokenable_id,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                DB::table('revoked_tokens')->upsert($rows, ['token_hash'], ['code', 'expires_at']);
            }

            $tokens->delete();
            DB::table('revoked_tokens')->where('expires_at', '<', $now)->delete();
        });
    }

    /**
     * A reactivated account must not wipe a phone that never signed out: its old
     * token then reads as an ordinary expired session, and the work it holds stays.
     */
    public function forgetForUser(int $userId, string $code): void
    {
        DB::table('revoked_tokens')->where('user_id', $userId)->where('code', $code)->delete();
    }

    /**
     * The revocation code for a bearer token that no longer authenticates, or
     * null when the token was never revoked (expired, malformed, or unknown).
     */
    public function codeFor(?string $bearer): ?string
    {
        if ($bearer === null || $bearer === '') {
            return null;
        }

        // Sanctum's plain-text form is "{id}|{secret}" and only the secret is hashed.
        $secret = str_contains($bearer, '|') ? explode('|', $bearer, 2)[1] : $bearer;

        $code = DB::table('revoked_tokens')
            ->where('token_hash', hash('sha256', $secret))
            ->where('expires_at', '>=', CarbonImmutable::now())
            ->value('code');

        return is_string($code) ? $code : null;
    }
}

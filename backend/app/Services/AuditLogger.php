<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Records before/after values, not just "something changed" (acceptance criterion AC-16).
 * Applied to state changes, stock movements, price and permission edits.
 *
 * Rows are immutable (database trigger), so anything personal must be removed
 * BEFORE the insert: a phone number written here could never be erased again.
 */
class AuditLogger
{
    /**
     * Every audit append serialises on this one transaction-scoped advisory key.
     * It replaced `LOCK TABLE audit_logs IN SHARE ROW EXCLUSIVE MODE`, which also
     * blocked autovacuum, index builds and every other writer of the table for the
     * life of the surrounding business transaction.
     */
    public const ADVISORY_LOCK_KEY = 4412802516;

    /** Plain SHA-256 chain: every row written before the HMAC key existed. */
    public const HASH_SHA256 = 1;

    /** HMAC-SHA256 chain keyed by DARAK_AUDIT_HMAC_KEY. */
    public const HASH_HMAC_SHA256 = 2;

    public const REDACTED = '[redacted]';

    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null,
    ): AuditLog {
        $authenticated = auth()->user();
        $key = self::hmacKey();
        $version = $key === null ? self::HASH_SHA256 : self::HASH_HMAC_SHA256;

        return DB::transaction(function () use ($action, $subject, $before, $after, $userId, $authenticated, $key, $version) {
            // PostgreSQL: wait for the previous append to commit so the chain never
            // forks. SQLite (local development only) serialises writers itself.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY]);
            }
            $previousHash = AuditLog::query()->latest('id')->value('entry_hash');
            $timestamp = now()->startOfSecond();
            $payload = [
                // audit_logs.user_id references back-office/mobile users. Client
                // portal identities live in a separate table and are captured in
                // the action payload instead of being written into this foreign key.
                'user_id' => $userId ?? ($authenticated instanceof User ? $authenticated->id : null),
                'action' => $action,
                'auditable_type' => $subject ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'before' => $this->redact($action, $before),
                'after' => $this->redact($action, $after),
                'ip' => $this->storedIp(),
                'user_agent' => $this->storedUserAgent(),
                'previous_hash' => $previousHash,
                'hash_version' => $version,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $payload['entry_hash'] = self::digestFor($payload, $version, $key);

            return AuditLog::create($payload);
        });
    }

    /**
     * Version-1 digest (plain SHA-256). Kept with its original signature: the
     * legacy backfill migration and every pre-HMAC row depend on it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function digest(array $payload): string
    {
        return hash('sha256', self::canonical($payload));
    }

    /**
     * Digest for a given chain version. Version 2 also binds the version number
     * itself, so a row cannot be relabelled to a weaker scheme without breaking.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function digestFor(array $payload, int $version, ?string $key = null): ?string
    {
        return match ($version) {
            self::HASH_SHA256 => self::digest($payload),
            self::HASH_HMAC_SHA256 => $key === null || $key === ''
                ? null
                : hash_hmac('sha256', self::canonical($payload, $version), $key),
            default => null,
        };
    }

    public static function hmacKey(): ?string
    {
        $key = config('darak.audit.hmac_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Keeps the network but drops the host: /24 for IPv4, /48 for IPv6. Enough to
     * spot "same office" or "foreign network" during an investigation without
     * storing a value that identifies one subscriber forever.
     */
    public static function truncateIp(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is really IPv4.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return inet_ntop(substr($packed, 0, 3)."\0").'/24';
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
    }

    /** Convenience for model updates: diffs only the keys that actually changed. */
    public function recordChange(string $action, Model $subject, array $original, ?int $userId = null): ?AuditLog
    {
        $after = [];
        $before = [];

        foreach ($subject->getAttributes() as $key => $value) {
            $was = $original[$key] ?? null;
            if ($was !== $value) {
                $before[$key] = $was;
                $after[$key] = $value;
            }
        }

        if ($after === []) {
            return null;
        }

        return $this->record($action, $subject, $before, $after, $userId);
    }

    /** @param array<string, mixed> $payload */
    private static function canonical(array $payload, int $version = self::HASH_SHA256): string
    {
        $createdAt = $payload['created_at'] instanceof \DateTimeInterface
            ? $payload['created_at']->format('Y-m-d H:i:s')
            : substr((string) $payload['created_at'], 0, 19);

        $fields = [
            'previous_hash' => $payload['previous_hash'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'action' => $payload['action'],
            'auditable_type' => $payload['auditable_type'] ?? null,
            'auditable_id' => $payload['auditable_id'] ?? null,
            'before' => $payload['before'] ?? null,
            'after' => $payload['after'] ?? null,
            'ip' => $payload['ip'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'created_at' => $createdAt,
        ];

        if ($version !== self::HASH_SHA256) {
            $fields['hash_version'] = $version;
        }

        return (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    private function redact(string $action, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $patterns = array_map('strtolower', (array) config('darak.audit.redact_keys', []));

        if (Str::is((array) config('darak.audit.redact_free_text_actions', []), $action)) {
            $patterns = array_merge($patterns, array_map('strtolower', (array) config('darak.audit.redact_free_text_keys', [])));
        }

        return $patterns === [] ? $values : $this->redactValues($values, $patterns);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>  $patterns
     * @return array<array-key, mixed>
     */
    private function redactValues(array $values, array $patterns): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && Str::is($patterns, strtolower($key))) {
                $values[$key] = $this->fingerprint($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->redactValues($value, $patterns);
            }
        }

        return $values;
    }

    /** "[redacted]:1a2b3c4d" — equal inputs give equal tags, the input never returns. */
    private function fingerprint(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        $normalised = is_scalar($value)
            ? mb_strtolower(trim((string) $value))
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return self::REDACTED.':'.substr(hash_hmac('sha256', $normalised, $this->fingerprintKey()), 0, 8);
    }

    private function fingerprintKey(): string
    {
        return self::hmacKey() ?? (string) config('app.key');
    }

    private function storedIp(): ?string
    {
        $ip = $this->safeIp();

        if ($ip === null || config('darak.audit.keep_full_ip')) {
            return $ip;
        }

        return self::truncateIp($ip);
    }

    private function storedUserAgent(): ?string
    {
        $agent = substr((string) Request::userAgent(), 0, 255);

        if ($agent === '') {
            return null;
        }

        if (config('darak.audit.keep_full_ip')) {
            return $agent;
        }

        return 'hmac:'.substr(hash_hmac('sha256', $agent, $this->fingerprintKey()), 0, 32);
    }

    private function safeIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null; // running from CLI / queue
        }
    }
}

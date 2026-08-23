<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Records before/after values, not just "something changed" (acceptance criterion AC-16).
 * Applied to state changes, stock movements, price and permission edits.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null,
    ): AuditLog {
        $authenticated = auth()->user();

        return DB::transaction(function () use ($action, $subject, $before, $after, $userId, $authenticated) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE audit_logs IN SHARE ROW EXCLUSIVE MODE');
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
                'before' => $before,
                'after' => $after,
                'ip' => $this->safeIp(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
                'previous_hash' => $previousHash,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $payload['entry_hash'] = self::digest($payload);

            return AuditLog::create($payload);
        });
    }

    /** @param array<string, mixed> $payload */
    public static function digest(array $payload): string
    {
        $createdAt = $payload['created_at'] instanceof \DateTimeInterface
            ? $payload['created_at']->format('Y-m-d H:i:s')
            : substr((string) $payload['created_at'], 0, 19);

        return hash('sha256', (string) json_encode([
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

    private function safeIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null; // running from CLI / queue
        }
    }
}

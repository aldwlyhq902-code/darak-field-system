<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
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
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $this->safeIp(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
        ]);
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

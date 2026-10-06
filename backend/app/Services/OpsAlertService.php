<?php

namespace App\Services;

use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One place for "an operator must act now" events: a failed backup, a failed
 * off-site copy, a rotation that could not run.
 *
 * Two independent paths so one broken channel does not silence the other:
 *   - a CRITICAL log line, which the optional webhook alert channel forwards
 *     (LOG_ALERT_WEBHOOK_URL), and
 *   - an in-app notice in every active owner's notification inbox.
 *
 * Raising an alert must never break the job that raised it.
 */
class OpsAlertService
{
    public const TYPE = 'ops.alert';

    /**
     * @param  array<string, mixed>  $context
     * @return int Inbox notices created (0 when deduplicated or on failure).
     */
    public function raise(string $kind, string $subject, string $body, array $context = []): int
    {
        try {
            Log::critical("[darak-ops] {$subject}: {$body}", ['kind' => $kind] + $context);
        } catch (Throwable $exception) {
            error_log('[darak-ops] '.$subject.': '.$body.' (logging failed: '.$exception->getMessage().')');
        }

        try {
            return $this->notifyOwners($kind, $subject, $body, $context);
        } catch (Throwable $exception) {
            error_log('[darak-ops] owner notification failed: '.$exception->getMessage());

            return 0;
        }
    }

    /** @param array<string, mixed> $context */
    private function notifyOwners(string $kind, string $subject, string $body, array $context): int
    {
        $owners = User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('role', User::ROLE_OWNER)->orWhere('is_platform_admin', true))
            ->get();

        $now = now();
        $created = 0;

        foreach ($owners as $owner) {
            // One notice per kind, owner and day: a job retried every few minutes
            // must not bury the inbox.
            $created += NotificationMessage::query()->insertOrIgnore([
                'type' => self::TYPE,
                'channel' => NotificationMessage::CHANNEL_IN_APP,
                'recipient_kind' => 'owner',
                'user_id' => $owner->id,
                'subject' => $subject,
                'body' => $body,
                'context' => json_encode(['kind' => $kind] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'queued',
                'attempts' => 0,
                'available_at' => $now,
                'idempotency_key' => "ops.{$kind}:{$owner->id}:".$now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $created;
    }
}

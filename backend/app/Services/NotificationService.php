<?php

namespace App\Services;

use App\Models\NotificationMessage;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Three notifications, an outbox, and honest delivery.
 *
 * What this deliberately is not: a general template engine with four channels.
 * That was cut because it doubles the test surface for no operating gain at two
 * vehicles, and because WhatsApp Business API templates need provider approval
 * that cannot be obtained before the company is registered.
 *
 * So WhatsApp starts as `whatsapp_manual`: the system composes the exact message
 * and a wa.me link, and the supervisor taps send. That is a real workflow rather
 * than a queue that silently fails.
 */
class NotificationService
{
    private const MAX_ATTEMPTS = 5;

    public function visitAssigned(Visit $visit, User $technician): ?NotificationMessage
    {
        $when = $visit->scheduled_start?->format('Y-m-d H:i') ?? 'غير محدد';
        $site = $visit->site?->name ?? '';
        $client = $visit->site?->client?->name ?? '';

        // Keyed per ASSIGNMENT, not per (visit, technician) pair. Keying on the
        // pair meant a visit moved A -> B -> back to A never notified A the second
        // time: the key was permanently consumed and A simply never learned the
        // job was theirs again.
        $generation = $visit->events()->where('event_type', 'assignment.changed')->count();

        return $this->queue(
            type: NotificationMessage::TYPE_VISIT_ASSIGNED,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'technician',
            key: "visit.assigned:{$visit->id}:{$technician->id}:{$generation}",
            body: "أُسندت إليك زيارة: {$client} — {$site}\nالموعد: {$when}",
            userId: $technician->id,
            visitId: $visit->id,
            phone: $technician->phone,
        );
    }

    public function slaAtRisk(Visit $visit, int $remainingMinutes): ?NotificationMessage
    {
        $client = $visit->site?->client?->name ?? '';
        $wo = $visit->workOrder?->wo_number ?? '';

        // Keyed per RISK EPISODE. Per-visit keying stopped the scheduler spamming
        // the outbox every ten minutes, but it also meant a reopened visit
        // approaching breach again warned nobody, ever. The due timestamp changes
        // when the work order is re-dated, so it identifies the episode.
        $episode = $visit->workOrder?->sla_due_at?->timestamp ?? 0;

        return $this->queue(
            type: NotificationMessage::TYPE_SLA_AT_RISK,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "sla.at_risk:{$visit->id}:{$episode}",
            body: "تحذير SLA: {$wo} — {$client}. المتبقي {$remainingMinutes} دقيقة داخل نافذة الخدمة.",
            visitId: $visit->id,
            context: ['remaining_minutes' => $remainingMinutes],
        );
    }

    public function reportReady(Visit $visit, ?string $clientPhone = null): ?NotificationMessage
    {
        $client = $visit->site?->client?->name ?? '';
        $site = $visit->site?->name ?? '';
        $date = $visit->closed_at?->format('Y-m-d H:i') ?? '';

        // Wording stays inside the operations-management position: a maintenance
        // record, never a compliance certificate.
        $body = "شكراً لكم — تمت زيارة الصيانة في {$client} ({$site}) بتاريخ {$date}.\n"
            . 'مرفق تقرير الحالة الفنية وسجل الصيانة الموقّع.';

        return $this->queue(
            type: NotificationMessage::TYPE_REPORT_READY,
            channel: NotificationMessage::CHANNEL_WHATSAPP_MANUAL,
            recipientKind: 'client',
            key: "report.ready:{$visit->id}",
            body: $body,
            visitId: $visit->id,
            phone: $clientPhone,
        );
    }

    /** Idempotent: the same key never produces a second message. */
    private function queue(
        string $type,
        string $channel,
        string $recipientKind,
        string $key,
        string $body,
        ?int $userId = null,
        ?int $visitId = null,
        ?string $phone = null,
        array $context = [],
    ): ?NotificationMessage {
        // Dedupe WITHOUT letting a constraint violation reach the connection.
        //
        // Catching the unique error and carrying on works on SQLite and is a trap
        // on PostgreSQL: a failed statement poisons the whole transaction, so
        // every later query in the same request dies with "current transaction is
        // aborted". Since this is called from inside the sync transaction, one
        // duplicate notification would have taken the entire batch down with it.
        //
        // insertOrIgnore compiles to ON CONFLICT DO NOTHING — no error is raised,
        // so there is nothing to poison.
        $now = CarbonImmutable::now();

        NotificationMessage::query()->insertOrIgnore([
            'type' => $type,
            'channel' => $channel,
            'recipient_kind' => $recipientKind,
            'user_id' => $userId,
            'visit_id' => $visitId,
            'phone' => $phone,
            'body' => $body,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'status' => 'queued',
            'attempts' => 0,
            'available_at' => $now,
            'idempotency_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return NotificationMessage::where('idempotency_key', $key)->first();
    }

    /** @return Collection<int, NotificationMessage> */
    public function due(int $limit = 100): Collection
    {
        return NotificationMessage::where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * In-app messages deliver immediately. Manual-WhatsApp ones wait for a human,
     * and are never marked sent on their behalf.
     */
    public function deliver(NotificationMessage $message): string
    {
        if ($message->channel === NotificationMessage::CHANNEL_WHATSAPP_MANUAL) {
            return 'awaiting_human';
        }

        $message->forceFill([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::now(),
            'attempts' => $message->attempts + 1,
            'last_error' => null,
        ])->save();

        return 'sent';
    }

    /** Exponential backoff, then a dead letter the supervisor sees. */
    public function recordFailure(NotificationMessage $message, string $error): void
    {
        $attempts = $message->attempts + 1;
        $dead = $attempts >= self::MAX_ATTEMPTS;

        $message->forceFill([
            'attempts' => $attempts,
            'last_error' => $error,
            'status' => $dead ? 'dead' : 'queued',
            'available_at' => $dead ? null : CarbonImmutable::now()->addMinutes(2 ** $attempts),
        ])->save();
    }

    public function markSentByHuman(NotificationMessage $message): void
    {
        $message->forceFill([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::now(),
            'attempts' => $message->attempts + 1,
        ])->save();
    }
}

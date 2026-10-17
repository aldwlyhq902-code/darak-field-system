<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\EmergencyReport;
use App\Models\ExternalDocument;
use App\Models\NotificationMessage;
use App\Models\OperatingBranch;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedback;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
            .'مرفق تقرير الحالة الفنية وسجل الصيانة الموقّع.';

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

    public function emergencyReported(EmergencyReport $report): ?NotificationMessage
    {
        $site = $report->site?->name ?? '';
        $client = $report->site?->client?->name ?? '';

        return $this->queue(
            type: NotificationMessage::TYPE_EMERGENCY_REPORTED,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "emergency.reported:{$report->id}",
            body: "بلاغ طارئ جديد: {$client} — {$site}. المبلّغ: {$report->reporter_name} ({$report->reporter_phone}).",
            context: ['emergency_report_id' => $report->id, 'severity' => $report->severity] + $this->tenantContext($report->site?->client),
        );
    }

    /**
     * One alert per site per hour once its QR reports pass the cap: the reports
     * are all kept and listed (flagged), but a flood must not bury the inbox.
     */
    public function emergencyFlood(Site $site): ?NotificationMessage
    {
        $client = $site->client?->name ?? '';

        return $this->queue(
            type: NotificationMessage::TYPE_EMERGENCY_REPORTED,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "emergency.flood:{$site->id}:".now()->format('YmdH'),
            body: "بلاغات كثيرة من ملصق QR لموقع {$client} — {$site->name} خلال وقت قصير. قُبلت كلها ووُسمت «تدفّق» بلا تنبيه لكل بلاغ. راجعها في البلاغات الطارئة، وإن كانت إساءة فدوّر رمز الملصق.",
            context: ['site_id' => $site->id, 'flood' => true] + $this->tenantContext($site->client),
        );
    }

    public function installmentDue(ContractInstallment $installment): ?NotificationMessage
    {
        $installment->loadMissing('contract.client.portalUsers');
        $client = $installment->contract->client;
        $days = now()->startOfDay()->diffInDays($installment->due_on, false);
        $timing = $days < 0 ? 'متأخرة '.abs($days).' يوم' : ($days === 0 ? 'مستحقة اليوم' : "تستحق بعد {$days} أيام");
        $body = "دفعة {$installment->contract->contract_no} — {$client->name}: {$timing}. المتبقي ".Money::format($installment->remaining()).' ر.س.';

        $message = $this->queue(
            type: NotificationMessage::TYPE_INSTALLMENT_DUE,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "installment.due:{$installment->id}:".now()->toDateString(),
            body: $body,
            context: ['installment_id' => $installment->id, 'days' => $days] + $this->tenantContext($client),
        );

        $phone = $client->portalUsers->firstWhere('is_active', true)?->phone;
        if ($phone) {
            $this->queue(
                type: NotificationMessage::TYPE_INSTALLMENT_DUE,
                channel: NotificationMessage::CHANNEL_WHATSAPP_MANUAL,
                recipientKind: 'client',
                key: "installment.client:{$installment->id}:".now()->toDateString(),
                body: $body,
                phone: $phone,
                context: ['installment_id' => $installment->id] + $this->tenantContext($client),
            );
        }

        return $message;
    }

    public function contractExpiring(Contract $contract): ?NotificationMessage
    {
        $contract->loadMissing('client');
        $days = now()->startOfDay()->diffInDays($contract->ends_on, false);

        return $this->queue(
            type: NotificationMessage::TYPE_CONTRACT_EXPIRING,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "contract.expiring:{$contract->id}:{$days}",
            body: "العقد {$contract->contract_no} للعميل {$contract->client->name} ينتهي بعد {$days} أيام ({$contract->ends_on->format('Y-m-d')}).",
            context: ['contract_id' => $contract->id, 'days' => $days] + $this->tenantContext($contract->client),
        );
    }

    public function feedbackAlert(VisitFeedback $feedback): ?NotificationMessage
    {
        $feedback->loadMissing('visit.site.client');
        $visit = $feedback->visit;

        return $this->queue(
            type: NotificationMessage::TYPE_FEEDBACK_ALERT,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "feedback.alert:{$feedback->id}",
            body: "تقييم يحتاج متابعة: {$visit->site?->client?->name} — {$visit->site?->name}. التقييم {$feedback->rating}/5.",
            visitId: $visit->id,
            context: ['feedback_id' => $feedback->id, 'rating' => $feedback->rating],
        );
    }

    /**
     * A tax document Darak cannot issue itself (manual invoicing): the finance
     * team issues it in the external tool, then records its number and date on
     * the invoices page — which keeps the durable list of what is still pending.
     */
    public function manualInvoiceTask(ExternalDocument $document): ?NotificationMessage
    {
        $document->loadMissing('client');
        $kind = $document->doc_type === ExternalDocument::TYPE_CREDIT_NOTE ? 'إشعار دائن' : 'فاتورة ضريبية';
        $client = $document->client?->name ?? '—';

        return $this->queue(
            type: NotificationMessage::TYPE_MANUAL_INVOICE,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: "invoice.manual:{$document->id}",
            body: "مطلوب إصدار {$kind} يدويًا للعميل {$client}: ".Money::format($document->amount).' ر.س + ضريبة '
                .Money::format($document->vat_amount).' ر.س. سجّل رقمه وتاريخه في صفحة الفواتير بعد إصداره.',
            visitId: $document->visit_id,
            context: ['external_document_id' => $document->id] + $this->tenantContext($document->client),
        );
    }

    public function complianceAlert(string $type, string $key, string $body, array $context = []): ?NotificationMessage
    {
        return $this->queue(
            type: $type,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: $key,
            body: $body,
            context: $this->withCompany($context),
        );
    }

    /**
     * Account-security events for the owner's inbox: lockouts and devices
     * waiting for approval. `$subjectUserId` is the account concerned, which is
     * also what scopes the message to that account's company in the panel.
     */
    public function securityAlert(string $key, string $body, ?int $subjectUserId = null, array $context = []): ?NotificationMessage
    {
        return $this->queue(
            type: NotificationMessage::TYPE_SECURITY_ALERT,
            channel: NotificationMessage::CHANNEL_IN_APP,
            recipientKind: 'supervisor',
            key: $key,
            body: $body,
            userId: $subjectUserId,
            context: $context,
        );
    }

    /**
     * Supervisor messages carry no recipient user, so the panel decides who may
     * read them from the tenant recorded here. A message without it is visible
     * only to platform administrators — which is how emergency reports and
     * installment reminders used to vanish from the owner's inbox.
     *
     * @return array<string, int>
     */
    private function tenantContext(?Client $client): array
    {
        return array_filter([
            'operating_company_id' => $client?->operating_company_id === null ? null : (int) $client->operating_company_id,
            'operating_branch_id' => $client?->operating_branch_id === null ? null : (int) $client->operating_branch_id,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withCompany(array $context): array
    {
        if (! isset($context['operating_company_id']) && isset($context['operating_branch_id'])) {
            $companyId = OperatingBranch::query()->withoutGlobalScopes()->whereKey($context['operating_branch_id'])->value('operating_company_id');
            if ($companyId !== null) {
                $context['operating_company_id'] = (int) $companyId;
            }
        }

        return $context;
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
        // Manual-WhatsApp rows stay queued until a person sends them, so they are
        // never "due" for the machine. Left in, a hundred unsent ones filled every
        // batch and the drain stopped delivering everything behind them.
        return NotificationMessage::where('status', 'queued')
            ->where('channel', '!=', NotificationMessage::CHANNEL_WHATSAPP_MANUAL)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Manual-WhatsApp messages still waiting for a person to send them. */
    public function awaitingHuman(): int
    {
        return NotificationMessage::where('status', 'queued')
            ->where('channel', NotificationMessage::CHANNEL_WHATSAPP_MANUAL)
            ->count();
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

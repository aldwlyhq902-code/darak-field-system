<?php

namespace App\Console\Commands;

use App\Models\NotificationMessage;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesPushService;
use Illuminate\Console\Command;

class SalesAlertsCommand extends Command
{
    protected $signature = 'darak:sales-alerts';

    protected $description = 'Create and push due follow-up, stale opportunity, and expiring quotation alerts';

    public function __construct(private readonly SalesPushService $push)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $created = 0;
        $active = ['new', 'qualified', 'proposal', 'negotiation'];
        $leads = SalesLead::query()->withoutGlobalScopes()->whereIn('stage', $active)->whereNotNull('owner_user_id')->with('owner')->get();
        foreach ($leads as $lead) {
            if ($lead->next_action_on?->lte(today())) {
                $created += $this->emitAlert($lead->owner, "sales.follow_up:{$lead->id}:".now()->toDateString(), 'متابعة مبيعات مستحقة', "حان موعد متابعة {$lead->company_name}.", ['lead_id' => $lead->id]);
            }
            $lastActivity = $lead->last_contacted_at ?? $lead->created_at;
            if ($lastActivity->lte(now()->subDays(max(1, (int) config('sales.stale_after_days'))))) {
                $created += $this->emitAlert($lead->owner, "sales.stale:{$lead->id}:".now()->startOfWeek()->toDateString(), 'فرصة تحتاج تنشيطًا', "لم تُسجل متابعة لفرصة {$lead->company_name} منذ {$lastActivity->diffForHumans()}.", ['lead_id' => $lead->id]);
            }
        }

        Quotation::query()->withoutGlobalScopes()->where('status', Quotation::STATUS_SENT)
            ->whereBetween('valid_until', [today(), today()->addDays(3)])->whereNotNull('created_by')->with('client')->get()
            ->each(function (Quotation $quote) use (&$created): void {
                $created += $this->emitAlert($quote->creator ?? User::find($quote->created_by), "sales.quote_expiring:{$quote->id}:".now()->toDateString(), 'عرض سعر يقترب من الانتهاء', "العرض {$quote->quote_no} للعميل {$quote->client?->name} ينتهي في {$quote->valid_until->format('Y/m/d')}.", ['quotation_id' => $quote->id]);
            });

        $this->info("Created {$created} sales alerts.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $context */
    private function emitAlert(?User $user, string $key, string $subject, string $body, array $context): int
    {
        if ($user === null || ! $user->is_active) {
            return 0;
        }
        $message = NotificationMessage::query()->firstOrCreate(['idempotency_key' => $key], [
            'type' => 'sales.alert', 'channel' => NotificationMessage::CHANNEL_IN_APP,
            'recipient_kind' => 'sales', 'user_id' => $user->id, 'subject' => $subject,
            'body' => $body, 'context' => $context + ['operating_branch_id' => $user->operating_branch_id],
            'status' => 'sent', 'attempts' => 1, 'sent_at' => now(),
        ]);
        if (! $message->wasRecentlyCreated) {
            return 0;
        }
        $this->push->send($user, $subject, $body, $context);

        return 1;
    }
}

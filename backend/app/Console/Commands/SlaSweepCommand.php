<?php

namespace App\Console\Commands;

use App\Models\Visit;
use App\Services\NotificationService;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Finds visits whose SLA is about to breach and queues one warning each.
 *
 * Three rules keep this from becoming noise or a memory problem:
 *   - Time outside the contract's service window does not count, so an evening
 *     ticket is not "at risk" at 2am when nobody is working.
 *   - The message is keyed per risk episode, so a risk that persists for hours
 *     produces one warning — but a reopened visit warns again.
 *   - Only visits due within the horizon are examined, in chunks. Loading every
 *     open visit ever, with eager loads, every ten minutes, grows without bound.
 */
class SlaSweepCommand extends Command
{
    protected $signature = 'darak:sla-sweep
                            {--threshold=25 : Warn when this percent of the budget remains}
                            {--horizon=48 : Only examine visits due within this many hours}';

    protected $description = 'Queue SLA warnings for visits approaching breach inside the service window.';

    public function handle(SlaCalculator $sla, NotificationService $notifications): int
    {
        $now = CarbonImmutable::now();
        $threshold = max(1, (int) $this->option('threshold')) / 100;
        $horizon = $now->addHours((int) $this->option('horizon'));

        $examined = 0;
        $queued = 0;
        $skippedOutOfWindow = 0;

        Visit::with(['workOrder.contract', 'site.client'])
            ->whereNotIn('state', [Visit::STATE_COMPLETED])
            ->whereHas('workOrder', fn ($q) => $q
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<=', $horizon)
                ->whereNotIn('status', ['closed', 'cancelled']))
            ->chunkById(200, function ($visits) use (
                &$examined, &$queued, &$skippedOutOfWindow, $sla, $notifications, $now, $threshold
            ) {
                foreach ($visits as $visit) {
                    $examined++;

                    $contract = $visit->workOrder->contract;
                    $due = $visit->workOrder->sla_due_at;
                    $budget = $visit->workOrder->sla_minutes_budget ?? 240;

                    // Outside service hours the clock is frozen; warning now
                    // would be a lie.
                    if (! $sla->isWithinWindow($now, $contract)) {
                        $skippedOutOfWindow++;
                        continue;
                    }

                    $remaining = $sla->remainingMinutes($now, $due, $contract);

                    if ($remaining > $budget * $threshold) {
                        continue;
                    }

                    if ($notifications->slaAtRisk($visit, $remaining)) {
                        $queued++;
                    }
                }
            });

        $this->info("فُحصت {$examined} زيارة · أُضيف {$queued} تحذيراً · {$skippedOutOfWindow} خارج نافذة الخدمة.");

        return self::SUCCESS;
    }
}

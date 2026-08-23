<?php

namespace App\Services;

use App\Models\MaintenancePlan;
use App\Models\Visit;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MaintenancePlanService
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly DispatchSuggestionService $dispatch,
    ) {}

    public function generateDuePlans(): int
    {
        $generated = 0;
        $ids = MaintenancePlan::query()->where('is_active', true)->whereDate('next_due_on', '<=', today())->pluck('id');

        foreach ($ids as $id) {
            $visit = DB::transaction(function () use ($id) {
                $plan = MaintenancePlan::query()->lockForUpdate()->find($id);
                if (! $plan || ! $plan->is_active || $plan->next_due_on->isAfter(today())) {
                    return null;
                }

                $plan->load(['site.assets', 'contract']);
                if ($plan->contract && ! $plan->contract->isActive()) {
                    return null;
                }

                $due = $plan->next_due_on->toImmutable();
                [$hour, $minute] = array_map('intval', explode(':', substr((string) ($plan->preferred_start ?: '09:00'), 0, 5)));
                $scheduledStart = CarbonImmutable::parse(max($due->toDateString(), today()->toDateString()))->setTime($hour, $minute);
                $scheduledEnd = $scheduledStart->addMinutes($plan->duration_minutes);
                $workOrder = WorkOrder::firstOrCreate(
                    ['wo_number' => 'WO-PM-'.$plan->id.'-'.$due->format('Ymd')],
                    [
                        'client_id' => $plan->client_id, 'site_id' => $plan->site_id,
                        'contract_id' => $plan->contract_id, 'asset_id' => $plan->asset_id,
                        'type' => 'preventive', 'priority' => 'normal', 'title' => $plan->title,
                        'description' => 'زيارة مولدة تلقائيًا من خطة الصيانة الوقائية رقم '.$plan->id.'.',
                        'reported_at' => now(), 'sla_minutes_budget' => $plan->contract?->sla_minutes,
                        'sla_due_at' => $plan->contract?->sla_minutes ? $this->sla->dueAt($scheduledStart, $plan->contract->sla_minutes, $plan->contract) : null,
                        'status' => 'scheduled', 'created_by' => $plan->created_by,
                    ],
                );

                $visit = $workOrder->visits()->firstOrCreate(
                    ['site_id' => $plan->site_id],
                    [
                        'assigned_user_id' => null, 'scheduled_start' => $scheduledStart,
                        'scheduled_end' => $scheduledEnd, 'state' => Visit::STATE_SCHEDULED, 'state_changed_at' => now(),
                        'required_asset_ids' => $plan->asset_id ? [$plan->asset_id] : $plan->site->assets->pluck('id')->all(),
                    ],
                );

                if ($plan->preferred_user_id && $visit->assigned_user_id === null) {
                    $preferred = $plan->preferredTechnician;
                    if ($preferred && $this->dispatch->conflicts($preferred, $visit->loadMissing('workOrder.asset')) === []) {
                        $visit->forceFill(['assigned_user_id' => $preferred->id])->save();
                    }
                }

                $next = $due->addDays($plan->frequency_days);
                while ($next->lte(today())) {
                    $next = $next->addDays($plan->frequency_days);
                }
                $plan->forceFill(['last_generated_on' => $due, 'next_due_on' => $next])->save();

                return $visit;
            });

            if ($visit) {
                $generated++;
            }
        }

        return $generated;
    }
}

<?php

namespace App\Services;

use App\Models\Visit;

class AutomaticReassignmentService
{
    public function __construct(private readonly DispatchSuggestionService $dispatch, private readonly RoutePlanningService $routes, private readonly AuditLogger $audit) {}

    public function run(): int
    {
        $count = 0;
        Visit::with(['technician', 'workOrder.asset', 'site', 'stockReservations'])->whereNotNull('assigned_user_id')
            ->whereBetween('scheduled_start', [now(), now()->addDays(7)])->where('state', Visit::STATE_SCHEDULED)
            ->each(function (Visit $visit) use (&$count) {
                if ($visit->technician && $this->dispatch->conflicts($visit->technician, $visit) === []) {
                    return;
                }
                $suggestion = $this->dispatch->suggest($visit, 1)->first();
                if ($suggestion === null) {
                    $visit->forceFill(['assigned_user_id' => null])->save();
                    $this->audit->record('visit.auto_unassigned', $visit, null, ['reason' => 'absence_or_vehicle_outage']);

                    return;
                }
                $old = $visit->assigned_user_id;
                $visit->forceFill(['assigned_user_id' => $suggestion['technician']->id])->save();
                $this->routes->recalculateDay($suggestion['technician'], $visit->scheduled_start);
                $this->audit->record('visit.auto_reassigned', $visit, ['assigned_user_id' => $old], ['assigned_user_id' => $visit->assigned_user_id]);
                $count++;
            });

        return $count;
    }
}

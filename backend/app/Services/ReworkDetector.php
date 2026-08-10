<?php

namespace App\Services;

use App\Models\Visit;
use App\Models\WorkOrder;

/**
 * Rework classification is proposed by the SYSTEM, not chosen by the technician.
 *
 * Review finding: if the technician picks the reason, the obvious move is to select
 * "client request" or leave the parent link empty and keep a clean first-time-fix
 * number. So: the system flags a repeat on the same asset within the window, the
 * technician cannot clear the flag, and only a supervisor may override it — with a
 * reason that lands in the audit log.
 *
 * Two figures are reported: strict FTF and FTF adjusted for supervisor overrides.
 * The gap between them is itself a supervision signal.
 */
class ReworkDetector
{
    public function __construct(private readonly int $windowDays = 30)
    {
    }

    /** Returns the visit this one appears to repeat, or null. */
    public function findParent(WorkOrder $workOrder): ?Visit
    {
        if ($workOrder->asset_id === null) {
            return null;
        }

        return Visit::query()
            ->whereHas('workOrder', fn ($q) => $q
                ->where('asset_id', $workOrder->asset_id)
                ->where('id', '!=', $workOrder->id))
            ->where('state', Visit::STATE_COMPLETED)
            ->where('closed_at', '>=', now()->subDays($this->windowDays))
            ->latest('closed_at')
            ->first();
    }

    /** Applies the system flag. Not billable by default, and it counts against FTF. */
    public function apply(Visit $visit, WorkOrder $workOrder): Visit
    {
        $parent = $this->findParent($workOrder);

        if ($parent === null) {
            return $visit;
        }

        $visit->forceFill([
            'parent_visit_id' => $parent->id,
            'is_rework' => true,
            'rework_system_flagged' => true,
            'rework_reason' => 'fault_returned',
            'is_billable' => false,
        ])->save();

        return $visit;
    }

    /**
     * Supervisor-only override. The technician has no path to this method.
     */
    public function override(Visit $visit, int $supervisorId, string $reason, string $note): Visit
    {
        $visit->forceFill([
            'is_rework' => false,
            'is_billable' => true,
            'rework_reason' => $reason,
            'rework_overridden_by' => $supervisorId,
            'rework_override_note' => $note,
        ])->save();

        return $visit;
    }

    /**
     * @return array{strict: float, adjusted: float, total: int, rework: int, overridden: int}
     */
    public function firstTimeFixRate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $base = Visit::query()
            ->where('state', Visit::STATE_COMPLETED)
            ->whereBetween('closed_at', [$from, $to]);

        $total = (clone $base)->count();
        $systemFlagged = (clone $base)->where('rework_system_flagged', true)->count();
        $overridden = (clone $base)->whereNotNull('rework_overridden_by')->count();
        $stillRework = (clone $base)->where('is_rework', true)->count();

        return [
            'strict' => $total > 0 ? round(($total - $systemFlagged) / $total * 100, 1) : 0.0,
            'adjusted' => $total > 0 ? round(($total - $stillRework) / $total * 100, 1) : 0.0,
            'total' => $total,
            'rework' => $stillRework,
            'overridden' => $overridden,
        ];
    }
}

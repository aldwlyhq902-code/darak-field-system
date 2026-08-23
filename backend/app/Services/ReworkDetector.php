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
    public function __construct(private readonly int $windowDays = 30) {}

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

        $summary = $base->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN rework_system_flagged = ? THEN 1 ELSE 0 END) AS system_flagged', [true])
            ->selectRaw('SUM(CASE WHEN rework_overridden_by IS NOT NULL THEN 1 ELSE 0 END) AS overridden')
            ->selectRaw('SUM(CASE WHEN is_rework = ? THEN 1 ELSE 0 END) AS still_rework', [true])
            ->first();

        $total = (int) ($summary?->total ?? 0);
        $systemFlagged = (int) ($summary?->system_flagged ?? 0);
        $overridden = (int) ($summary?->overridden ?? 0);
        $stillRework = (int) ($summary?->still_rework ?? 0);

        return [
            'strict' => $total > 0 ? round(($total - $systemFlagged) / $total * 100, 1) : 0.0,
            'adjusted' => $total > 0 ? round(($total - $stillRework) / $total * 100, 1) : 0.0,
            'total' => $total,
            'rework' => $stillRework,
            'overridden' => $overridden,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\EmployeeLeave;
use App\Models\TechnicianAbsence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeaveManagementService
{
    public function __construct(private readonly AutomaticReassignmentService $reassignment) {}

    public function days(string|\DateTimeInterface $start, string|\DateTimeInterface $end): int
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        return $from->diffInDays($to) + 1;
    }

    /** @return array{entitlement: float, used: float, pending: float, remaining: float} */
    public function balance(User $user, ?int $year = null): array
    {
        $year ??= (int) now()->year;
        $entitlement = (float) ($user->employeeProfile?->annual_leave_days ?? 21);
        $rangeStart = CarbonImmutable::create($year, 1, 1);
        $rangeEnd = $rangeStart->endOfYear();
        $leaves = $user->employeeLeaves()->where('type', 'annual')
            ->where('starts_on', '<=', $rangeEnd)->where('ends_on', '>=', $rangeStart)->get();

        $sum = function (string $status) use ($leaves, $rangeStart, $rangeEnd): float {
            return (float) $leaves->where('status', $status)->sum(function (EmployeeLeave $leave) use ($rangeStart, $rangeEnd): int {
                $start = $leave->starts_on->greaterThan($rangeStart) ? $leave->starts_on : $rangeStart;
                $end = $leave->ends_on->lessThan($rangeEnd) ? $leave->ends_on : $rangeEnd;

                return $start->diffInDays($end) + 1;
            });
        };

        $used = $sum('approved');
        $pending = $sum('pending');

        return ['entitlement' => $entitlement, 'used' => $used, 'pending' => $pending, 'remaining' => max(0, $entitlement - $used)];
    }

    public function approve(EmployeeLeave $leave, User $approver): int
    {
        if ($leave->status !== 'pending') {
            throw new RuntimeException('لا يمكن اعتماد طلب إجازة سبق اتخاذ قرار بشأنه.');
        }

        DB::transaction(function () use ($leave, $approver): void {
            $leave->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            if ($leave->user->isTechnician()) {
                TechnicianAbsence::firstOrCreate(
                    ['employee_leave_id' => $leave->id],
                    [
                        'user_id' => $leave->user_id,
                        'starts_on' => $leave->starts_on,
                        'ends_on' => $leave->ends_on,
                        'reason' => 'إجازة معتمدة: '.$leave->type,
                        'status' => 'approved',
                        'approved_by' => $approver->id,
                    ],
                );
            }
        });

        return $leave->user->isTechnician() ? $this->reassignment->run() : 0;
    }

    public function reject(EmployeeLeave $leave, User $approver, string $note): void
    {
        if ($leave->status !== 'pending') {
            throw new RuntimeException('لا يمكن رفض طلب إجازة سبق اتخاذ قرار بشأنه.');
        }

        $leave->forceFill([
            'status' => 'rejected', 'approved_by' => $approver->id,
            'approved_at' => now(), 'response_note' => $note,
        ])->save();
    }
}

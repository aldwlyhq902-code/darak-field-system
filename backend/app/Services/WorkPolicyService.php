<?php

namespace App\Services;

use App\Models\OperatingBranch;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Checks a proposed work segment for a technician against the company's work
 * policy version in force on that date. The numbers come ONLY from the policy
 * a qualified specialist supplied; with no policy, or no value for a rule,
 * nothing is checked and no violation is reported.
 *
 * A shift is the technician's scheduled work on one calendar day. Only
 * violations the proposed segment causes or worsens are reported, so a new
 * assignment is never blamed for a schedule that was already out of policy.
 */
class WorkPolicyService
{
    private const DEFAULT_VISIT_MINUTES = 120;

    public function activeFor(User $technician, CarbonInterface $on): ?WorkPolicy
    {
        $companyId = $technician->operating_company_id
            ?? ($technician->operating_branch_id
                ? OperatingBranch::query()->whereKey($technician->operating_branch_id)->value('operating_company_id')
                : null);

        return $companyId === null ? null : WorkPolicy::query()->effectiveOn((int) $companyId, $on)->first();
    }

    /**
     * @param  int|null  $exceptVisitId  a visit already assigned to this technician that the segment replaces
     * @return array<int, string> Arabic violation messages; empty when compliant or when no policy applies
     */
    public function violations(User $technician, CarbonInterface $start, CarbonInterface $end, ?int $exceptVisitId = null): array
    {
        $start = CarbonImmutable::instance($start)->setTimezone(config('app.timezone'));
        $end = CarbonImmutable::instance($end)->setTimezone(config('app.timezone'));
        if ($end->lte($start)) {
            return [];
        }

        $policy = $this->activeFor($technician, $start);
        if ($policy === null) {
            return [];
        }

        $maxShift = $policy->rule('max_shift_minutes');
        $maxDaily = $policy->rule('max_daily_minutes');
        $maxWeekly = $policy->rule('max_weekly_minutes');
        $minRest = $policy->rule('min_rest_between_shifts_minutes');
        if ($maxShift === null && $maxDaily === null && $maxWeekly === null && $minRest === null) {
            return [];
        }

        $existing = $this->scheduledIntervals($technician, $start->startOfDay()->subDays(8), $end->endOfDay()->addDays(8), $exceptVisitId);
        $proposed = [...$existing, [$start, $end]];
        $label = "سياسة العمل الإصدار {$policy->version}";
        $messages = [];

        for ($day = $start->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            $dayEnd = $day->addDay();
            $date = $day->format('Y-m-d');

            if ($maxDaily !== null) {
                $after = $this->workedMinutes($proposed, $day, $dayEnd);
                if ($after > $maxDaily && $after > $this->workedMinutes($existing, $day, $dayEnd)) {
                    $messages[] = "مجموع العمل يوم {$date} سيبلغ {$after} دقيقة ويتجاوز الحد اليومي ({$maxDaily} دقيقة) — {$label}.";
                }
            }

            if ($maxShift !== null) {
                $after = $this->shiftSpan($proposed, $day, $dayEnd);
                if ($after > $maxShift && $after > $this->shiftSpan($existing, $day, $dayEnd)) {
                    $messages[] = "مدة الوردية يوم {$date} ستبلغ {$after} دقيقة وتتجاوز الحد ({$maxShift} دقيقة) — {$label}.";
                }
            }

            if ($minRest !== null) {
                foreach ($this->restGaps($proposed, $day, $dayEnd) as [$fromDate, $toDate, $gap]) {
                    $before = collect($this->restGaps($existing, $day, $dayEnd))
                        ->first(fn (array $row) => $row[0] === $fromDate && $row[1] === $toDate)[2] ?? null;
                    if ($gap < $minRest && ($before === null || $gap < $before)) {
                        $messages[] = "الراحة بين وردية {$fromDate} ووردية {$toDate} ستكون {$gap} دقيقة وهي أقل من الحد الأدنى ({$minRest} دقيقة) — {$label}.";
                    }
                }
            }
        }

        if ($maxWeekly !== null) {
            // Every 7-day window that contains the segment, without assuming
            // which weekday a "week" starts on.
            $worst = null;
            for ($offset = 6; $offset >= 0; $offset--) {
                $from = $start->startOfDay()->subDays($offset);
                $to = $from->addDays(7);
                $after = $this->workedMinutes($proposed, $from, $to);
                if ($after > $maxWeekly && $after > $this->workedMinutes($existing, $from, $to)
                    && ($worst === null || $after > $worst[0])) {
                    $worst = [$after, $from, $to->subDay()];
                }
            }
            if ($worst !== null) {
                $messages[] = "مجموع العمل خلال 7 أيام متتالية ({$worst[1]->format('Y-m-d')} إلى {$worst[2]->format('Y-m-d')}) سيبلغ {$worst[0]} دقيقة ويتجاوز الحد ({$maxWeekly} دقيقة) — {$label}.";
            }
        }

        return array_values(array_unique($messages));
    }

    /** @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private function scheduledIntervals(User $technician, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptVisitId): array
    {
        // All of the technician's work counts, whichever branch it belongs to,
        // so the actor's tenant scope is deliberately not applied here.
        return Visit::withoutGlobalScopes()
            ->where('assigned_user_id', $technician->id)
            ->when($exceptVisitId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->whereNotNull('scheduled_start')
            ->where('scheduled_start', '<', $to)
            ->where('scheduled_start', '>=', $from->subDay())
            ->get(['id', 'scheduled_start', 'scheduled_end'])
            ->map(function (Visit $visit): array {
                $start = CarbonImmutable::instance($visit->scheduled_start);
                $end = $visit->scheduled_end ? CarbonImmutable::instance($visit->scheduled_end) : $start->addMinutes(self::DEFAULT_VISIT_MINUTES);

                return [$start, $end->gt($start) ? $end : $start->addMinutes(self::DEFAULT_VISIT_MINUTES)];
            })
            ->all();
    }

    /** Minutes covered by the union of the intervals inside [from, to). */
    private function workedMinutes(array $intervals, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $clipped = collect($intervals)
            ->map(fn (array $i) => [$i[0]->max($from), $i[1]->min($to)])
            ->filter(fn (array $i) => $i[1]->gt($i[0]))
            ->sortBy(fn (array $i) => $i[0]->getTimestamp())->values();

        $total = 0;
        $cursor = null;
        foreach ($clipped as [$s, $e]) {
            if ($cursor !== null && $s->lt($cursor)) {
                $s = $cursor;
            }
            if ($e->gt($s)) {
                $total += $e->getTimestamp() - $s->getTimestamp();
            }
            $cursor = $cursor === null ? $e : $cursor->max($e);
        }

        return intdiv($total, 60);
    }

    /** First start to last end of the day's work, in minutes (0 when idle). */
    private function shiftSpan(array $intervals, CarbonImmutable $day, CarbonImmutable $dayEnd): int
    {
        [$first, $last] = $this->dayBounds($intervals, $day, $dayEnd);

        return $first === null ? 0 : intdiv($last->getTimestamp() - $first->getTimestamp(), 60);
    }

    /**
     * Rest before and after this day's shift, against the nearest shift on an
     * earlier / later day.
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function restGaps(array $intervals, CarbonImmutable $day, CarbonImmutable $dayEnd): array
    {
        [$first, $last] = $this->dayBounds($intervals, $day, $dayEnd);
        if ($first === null) {
            return [];
        }

        $gaps = [];
        $previous = collect($intervals)->filter(fn (array $i) => $i[0]->lt($day) && $i[1]->lte($first))
            ->sortByDesc(fn (array $i) => $i[1]->getTimestamp())->first();
        if ($previous !== null) {
            $gaps[] = [$previous[0]->format('Y-m-d'), $day->format('Y-m-d'), intdiv($first->getTimestamp() - $previous[1]->getTimestamp(), 60)];
        }
        $next = collect($intervals)->filter(fn (array $i) => $i[0]->gte($dayEnd) && $i[0]->gte($last))
            ->sortBy(fn (array $i) => $i[0]->getTimestamp())->first();
        if ($next !== null) {
            $gaps[] = [$day->format('Y-m-d'), $next[0]->format('Y-m-d'), intdiv($next[0]->getTimestamp() - $last->getTimestamp(), 60)];
        }

        return $gaps;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function dayBounds(array $intervals, CarbonImmutable $day, CarbonImmutable $dayEnd): array
    {
        $first = null;
        $last = null;
        foreach ($intervals as [$s, $e]) {
            $s = $s->max($day);
            $e = $e->min($dayEnd);
            if ($e->lte($s)) {
                continue;
            }
            $first = $first === null ? $s : $first->min($s);
            $last = $last === null ? $e : $last->max($e);
        }

        return [$first, $last];
    }
}

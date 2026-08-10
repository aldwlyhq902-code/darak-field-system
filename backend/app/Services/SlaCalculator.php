<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * SLA inside the service window.
 *
 * This class exists because of a contradiction found in review: the dispatch rules
 * forbid any assignment ending after 23:00, while the package promises a 2-4 hour
 * response. A ticket raised at 21:30 with a 4-hour SLA cannot be served by 01:30,
 * so a naive wall-clock countdown would show every evening ticket as breached by
 * morning and the dashboard would lie.
 *
 * The rule adopted: the SLA budget is consumed ONLY inside the contract's service
 * window (default 07:00-23:00). Time outside the window is frozen and the countdown
 * resumes when the window reopens. What we sell the client is a response *within
 * service hours* — the contract text must say the same thing.
 */
class SlaCalculator
{
    private const DEFAULT_START = '07:00:00';
    private const DEFAULT_END = '23:00:00';

    /**
     * Due timestamp after consuming $minutes of in-window time from $from.
     */
    public function dueAt(CarbonInterface $from, int $minutes, ?Contract $contract = null): CarbonImmutable
    {
        [$startTime, $endTime] = $this->window($contract);

        $cursor = CarbonImmutable::instance($from);
        $remaining = max(0, $minutes);

        // A window that is never open (start == end) cannot consume anything, so
        // fall back to wall clock rather than looping. isWithinWindow() agrees:
        // the two must never disagree, which was the earlier bug.
        if ($startTime === $endTime) {
            return $cursor->addMinutes($remaining);
        }

        $iterations = 0;
        while ($remaining > 0 && $iterations < 740) {
            $iterations++;

            [$windowOpen, $windowClose] = $this->segmentFor($cursor, $startTime, $endTime);

            if ($cursor->lt($windowOpen)) {
                // Before opening: jump to the open time, nothing consumed.
                $cursor = $windowOpen;
                continue;
            }

            if ($cursor->gte($windowClose)) {
                // After closing: jump to the next opening.
                $cursor = $this->at($cursor->addDay(), $startTime);
                continue;
            }

            $availableNow = $cursor->diffInMinutes($windowClose, false);

            if ($availableNow >= $remaining) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= (int) $availableNow;
            $cursor = $windowClose;
        }

        return $cursor;
    }

    /**
     * In-window minutes actually elapsed between two instants.
     */
    public function elapsedMinutes(CarbonInterface $from, CarbonInterface $to, ?Contract $contract = null): int
    {
        [$startTime, $endTime] = $this->window($contract);

        $cursor = CarbonImmutable::instance($from);
        $end = CarbonImmutable::instance($to);

        if ($end->lte($cursor)) {
            return 0;
        }

        // Same guard as dueAt: a never-open window measures wall-clock time rather
        // than silently returning zero, which is what painted every ticket red.
        if ($startTime === $endTime) {
            return (int) $cursor->diffInMinutes($end, false);
        }

        $total = 0;
        $iterations = 0;

        while ($cursor->lt($end) && $iterations < 740) {
            $iterations++;

            [$windowOpen, $windowClose] = $this->segmentFor($cursor, $startTime, $endTime);

            if ($cursor->lt($windowOpen)) {
                $cursor = $windowOpen;
                continue;
            }

            if ($cursor->gte($windowClose)) {
                $cursor = $this->at($cursor->addDay(), $startTime);
                continue;
            }

            $segmentEnd = $end->lt($windowClose) ? $end : $windowClose;
            $total += (int) $cursor->diffInMinutes($segmentEnd, false);
            $cursor = $segmentEnd->eq($end) ? $end : $windowClose;
        }

        return $total;
    }

    /**
     * Remaining in-window minutes before breach. Negative means already breached.
     */
    public function remainingMinutes(CarbonInterface $now, CarbonInterface $dueAt, ?Contract $contract = null): int
    {
        if ($now->lte($dueAt)) {
            return $this->elapsedMinutes($now, $dueAt, $contract);
        }

        return -1 * $this->elapsedMinutes($dueAt, $now, $contract);
    }

    /** green | amber | red — drives the countdown colour for technician and supervisor. */
    public function status(CarbonInterface $now, CarbonInterface $dueAt, int $budgetMinutes, ?Contract $contract = null): string
    {
        $remaining = $this->remainingMinutes($now, $dueAt, $contract);

        if ($remaining <= 0) {
            return 'red';
        }

        $budget = max(1, $budgetMinutes);

        return $remaining <= (int) round($budget * 0.25) ? 'amber' : 'green';
    }

    public function isWithinWindow(CarbonInterface $moment, ?Contract $contract = null): bool
    {
        [$startTime, $endTime] = $this->window($contract);
        $m = CarbonImmutable::instance($moment);

        $start = $this->at($m, $startTime);
        $end = $this->at($m, $endTime);

        // An overnight window (15:00-02:00) is legitimate for evening venues and
        // the schema accepts it. Treating it as zero-length made every ticket show
        // red the moment it was created, because remainingMinutes always returned
        // zero while dueAt silently fell back to the wall clock.
        if ($this->isOvernight($startTime, $endTime)) {
            return $m->gte($start) || $m->lt($end);
        }

        if ($startTime === $endTime) {
            return false; // a genuinely zero-length window: never open
        }

        return $m->gte($start) && $m->lt($end);
    }

    private function isOvernight(string $start, string $end): bool
    {
        return strcmp($end, $start) < 0;
    }

    /** Start of the window segment containing (or next following) $moment. */
    private function segmentFor(CarbonImmutable $moment, string $startTime, string $endTime): array
    {
        $open = $this->at($moment, $startTime);
        $close = $this->at($moment, $endTime);

        if (! $this->isOvernight($startTime, $endTime)) {
            return [$open, $close];
        }

        // Overnight: the segment that started yesterday runs until this morning.
        if ($moment->lt($close)) {
            return [$this->at($moment->subDay(), $startTime), $close];
        }

        return [$open, $this->at($moment->addDay(), $endTime)];
    }

    /** @return array{0:string,1:string} */
    private function window(?Contract $contract): array
    {
        return [
            $this->normalise($contract?->service_window_start, self::DEFAULT_START),
            $this->normalise($contract?->service_window_end, self::DEFAULT_END),
        ];
    }

    private function normalise(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $string = $value instanceof \DateTimeInterface ? $value->format('H:i:s') : (string) $value;

        return strlen($string) === 5 ? $string . ':00' : $string;
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$h, $i, $s] = array_pad(explode(':', $time), 3, '0');

        return $day->setTime((int) $h, (int) $i, (int) $s);
    }

    private function windowMinutesPerDay(string $start, string $end): int
    {
        $base = CarbonImmutable::create(2000, 1, 1, 0, 0, 0);

        return (int) $this->at($base, $start)->diffInMinutes($this->at($base, $end), false);
    }
}

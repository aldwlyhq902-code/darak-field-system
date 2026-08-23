<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RoutePlanningService
{
    public function __construct(private readonly TrafficRouteProvider $traffic) {}

    /** @return array{minutes:int,distance_km:float,provider:string} */
    public function estimate(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2, ?CarbonInterface $at = null): array
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return ['minutes' => (int) config('darak.default_travel_minutes', 30), 'distance_km' => 0, 'provider' => 'default'];
        }
        $live = $this->traffic->estimate($lat1, $lng1, $lat2, $lng2, $at);
        if ($live !== null) {
            return $live;
        }
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        $km = 6371 * 2 * atan2(sqrt($a), sqrt(max(.0000001, 1 - $a)));
        $hour = ($at ?? now())->hour;
        $trafficFactor = in_array($hour, [7, 8, 9, 16, 17, 18, 19], true) ? 1.55 : 1.2;

        return ['minutes' => max(10, (int) ceil(($km / 45 * 60) * $trafficFactor + 8)), 'distance_km' => round($km, 2), 'provider' => 'local-fallback'];
    }

    public function travelMinutes(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2, ?CarbonInterface $at = null): int
    {
        return $this->estimate($lat1, $lng1, $lat2, $lng2, $at)['minutes'];
    }

    /** @return array<int, string> */
    public function timingConflicts(User $technician, Visit $candidate): array
    {
        if ($candidate->scheduled_start === null) {
            return [];
        }
        $candidate->loadMissing('site');
        $dayVisits = Visit::with('site')->where('assigned_user_id', $technician->id)
            ->whereDate('scheduled_start', $candidate->scheduled_start)->where('id', '!=', $candidate->id)
            ->where('state', '!=', Visit::STATE_COMPLETED)->orderBy('scheduled_start')->get();
        $previous = $dayVisits->where('scheduled_end', '<=', $candidate->scheduled_start)->sortByDesc('scheduled_end')->first();
        $next = $dayVisits->where('scheduled_start', '>=', $candidate->scheduled_end)->sortBy('scheduled_start')->first();
        $conflicts = [];
        if ($previous) {
            $minutes = $this->travelMinutes($this->coordinate($previous->site?->lat), $this->coordinate($previous->site?->lng), $this->coordinate($candidate->site?->lat), $this->coordinate($candidate->site?->lng), $previous->scheduled_end);
            if ($previous->scheduled_end->copy()->addMinutes($minutes)->gt($candidate->scheduled_start)) {
                $conflicts[] = "لا توجد مهلة طريق واقعية بعد الزيارة السابقة ({$minutes} دقيقة مطلوبة)";
            }
        }
        if ($next) {
            $minutes = $this->travelMinutes($this->coordinate($candidate->site?->lat), $this->coordinate($candidate->site?->lng), $this->coordinate($next->site?->lat), $this->coordinate($next->site?->lng), $candidate->scheduled_end);
            if ($candidate->scheduled_end->copy()->addMinutes($minutes)->gt($next->scheduled_start)) {
                $conflicts[] = "لا توجد مهلة طريق واقعية قبل الزيارة التالية ({$minutes} دقيقة مطلوبة)";
            }
        }

        return $conflicts;
    }

    /** @return Collection<int, Visit> */
    public function recalculateDay(User $technician, CarbonInterface $day): Collection
    {
        $visits = Visit::with('site')->where('assigned_user_id', $technician->id)->whereDate('scheduled_start', $day)
            ->where('state', '!=', Visit::STATE_COMPLETED)->orderBy('scheduled_start')->get();
        $previous = null;
        foreach ($visits as $index => $visit) {
            $route = $previous ? $this->estimate($this->coordinate($previous->site?->lat), $this->coordinate($previous->site?->lng), $this->coordinate($visit->site?->lat), $this->coordinate($visit->site?->lng), $previous->scheduled_end) : ['minutes' => 0, 'distance_km' => 0, 'provider' => 'first-visit'];
            $eta = $previous ? $previous->scheduled_end->copy()->addMinutes($route['minutes']) : $visit->scheduled_start;
            $visit->forceFill(['route_sequence' => $index + 1, 'estimated_arrival_at' => $eta, 'route_provider' => $route['provider'], 'route_distance_km' => $route['distance_km']])->save();
            $previous = $visit;
        }

        return $visits;
    }

    private function coordinate(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}

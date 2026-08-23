<?php

namespace App\Services;

use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOutage;
use App\Models\Visit;
use Illuminate\Support\Collection;

class DispatchSuggestionService
{
    public function __construct(private readonly RoutePlanningService $routes, private readonly InventoryService $inventory) {}

    /** @return Collection<int, array{technician:User,score:int,reasons:array<int,string>}> */
    public function suggest(Visit $visit, int $limit = 3): Collection
    {
        $visit->loadMissing(['workOrder.asset', 'site']);

        return User::query()->where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->get()
            ->map(function (User $technician) use ($visit) {
                if ($this->conflicts($technician, $visit) !== []) {
                    return null;
                }

                $start = $visit->scheduled_start;
                $dailyLoad = $start ? Visit::query()
                    ->where('assigned_user_id', $technician->id)->whereDate('scheduled_start', $start)
                    ->where('id', '!=', $visit->id)->where('state', '!=', Visit::STATE_COMPLETED)->count() : 0;
                $specialty = $visit->workOrder?->asset?->type;
                $score = 100 - ($dailyLoad * 15);
                $reasons = ["الحمل في اليوم: {$dailyLoad} زيارة"];

                if ($visit->workOrder?->priority === 'emergency' && $technician->is_emergency_backup) {
                    $score += 30;
                    $reasons[] = 'فني احتياط للطوارئ';
                }

                if ($specialty && $technician->hasSpecialty($specialty)) {
                    $score += 25;
                    $reasons[] = 'تخصص مطابق';
                }

                $vehicle = Vehicle::with('stockLocation')->where('assigned_user_id', $technician->id)->where('is_active', true)->first();
                if ($vehicle?->stockLocation) {
                    $missing = $visit->stockReservations->filter(fn ($reservation) => $this->inventory->availableBalance($reservation->part_id, $vehicle->stockLocation->id, $visit->id) < (float) $reservation->qty)->count();
                    if ($missing === 0 && $visit->stockReservations->isNotEmpty()) {
                        $score += 12;
                        $reasons[] = 'قطع الزيارة متاحة في سيارة الفني';
                    } elseif ($missing > 0) {
                        $score -= $missing * 10;
                        $reasons[] = "ينقص السيارة {$missing} صنف محجوز";
                    }
                }

                $distance = $this->distanceFromNearestJob($technician, $visit);
                if ($distance !== null) {
                    $score -= min(30, (int) round($distance));
                    $reasons[] = 'المسافة التقريبية من أقرب مهمة '.number_format($distance, 1).' كم';
                }

                return ['technician' => $technician, 'score' => $score, 'reasons' => $reasons];
            })->filter()->sortByDesc('score')->take($limit)->values();
    }

    /** @return array<int, string> */
    public function conflicts(User $technician, Visit $visit): array
    {
        $reasons = [];
        $start = $visit->scheduled_start;
        $end = $visit->scheduled_end ?? $start?->copy()->addHours(2);

        if (! $technician->is_active || ! $technician->isTechnician()) {
            $reasons[] = 'المستخدم ليس فنيًا نشطًا';
        }
        if ($start && $end && ! $technician->isWithinShift($start, $end)) {
            $reasons[] = 'الزيارة خارج ورديته';
        }

        $specialty = $visit->workOrder?->asset?->type;
        if ($specialty && ($technician->specialties ?? []) !== [] && ! $technician->hasSpecialty($specialty)) {
            $reasons[] = "التخصص المطلوب {$specialty} غير متوفر";
        }

        if ($start && $end && Visit::query()->where('assigned_user_id', $technician->id)
            ->where('id', '!=', $visit->id)->where('state', '!=', Visit::STATE_COMPLETED)
            ->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)->exists()) {
            $reasons[] = 'لدى الفني زيارة متداخلة';
        }

        if ($start && TechnicianAbsence::where('user_id', $technician->id)->where('status', 'approved')->whereDate('starts_on', '<=', $start)->whereDate('ends_on', '>=', $start)->exists()) {
            $reasons[] = 'الفني في غياب معتمد';
        }

        $vehicle = Vehicle::where('assigned_user_id', $technician->id)->first();
        if ($start && $vehicle && VehicleOutage::where('vehicle_id', $vehicle->id)->where('status', 'open')->where('starts_at', '<=', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start))->exists()) {
            $reasons[] = 'سيارة الفني متعطلة في هذا الموعد';
        }

        $reasons = array_merge($reasons, $this->routes->timingConflicts($technician, $visit));

        return $reasons;
    }

    private function distanceFromNearestJob(User $technician, Visit $visit): ?float
    {
        if ($visit->site?->lat === null || $visit->site?->lng === null || ! $visit->scheduled_start) {
            return null;
        }

        $nearest = Visit::query()->with('site')->where('assigned_user_id', $technician->id)
            ->whereDate('scheduled_start', $visit->scheduled_start)->where('id', '!=', $visit->id)->get()
            ->filter(fn (Visit $job) => $job->site?->lat !== null && $job->site?->lng !== null)
            ->map(fn (Visit $job) => $this->haversine((float) $visit->site->lat, (float) $visit->site->lng, (float) $job->site->lat, (float) $job->site->lng))
            ->min();

        return $nearest === null ? null : (float) $nearest;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

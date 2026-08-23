<?php

namespace App\Services;

use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class VehicleLoadSuggestionService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forTomorrow(): Collection
    {
        return User::where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->get()->map(function (User $technician) {
            $vehicleLocation = StockLocation::whereHas('vehicle', fn ($q) => $q->where('assigned_user_id', $technician->id))->first();
            if ($vehicleLocation === null) {
                return null;
            }
            $visitIds = Visit::where('assigned_user_id', $technician->id)->whereDate('scheduled_start', CarbonImmutable::tomorrow())->pluck('id');
            if ($visitIds->isEmpty()) {
                return null;
            }
            $reserved = StockReservation::whereIn('visit_id', $visitIds)->where('status', 'reserved')
                ->selectRaw('part_id, SUM(qty) qty')->groupBy('part_id')->pluck('qty', 'part_id');
            $history = StockMove::where('move_type', StockMove::VISIT_ISSUE)
                ->whereHas('visit', fn ($q) => $q->where('assigned_user_id', $technician->id)->where('scheduled_start', '>=', now()->subDays(60)))
                ->selectRaw('part_id, SUM(qty) / GREATEST(COUNT(DISTINCT visit_id), 1) avg_qty')->groupBy('part_id')->pluck('avg_qty', 'part_id');
            $partIds = $reserved->keys()->merge($history->keys())->unique();
            $items = $partIds->map(function ($partId) use ($reserved, $history, $vehicleLocation, $visitIds) {
                $needed = max((float) ($reserved[$partId] ?? 0), (float) ($history[$partId] ?? 0) * max(1, $visitIds->count()));
                $onVehicle = $this->inventory->availableBalance((int) $partId, $vehicleLocation->id);

                return ['part_id' => (int) $partId, 'suggested_qty' => round(max(0, $needed - $onVehicle), 3), 'on_vehicle' => $onVehicle];
            })->filter(fn ($row) => $row['suggested_qty'] > 0)->values();

            return ['technician' => $technician, 'location' => $vehicleLocation, 'visits_count' => $visitIds->count(), 'items' => $items];
        })->filter()->values();
    }
}

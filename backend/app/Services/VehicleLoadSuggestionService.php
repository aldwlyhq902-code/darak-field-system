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
        $technicians = User::where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->get();
        if ($technicians->isEmpty()) {
            return collect();
        }

        $technicianIds = $technicians->modelKeys();
        $locations = StockLocation::with('vehicle')
            ->where('type', StockLocation::TYPE_VEHICLE)
            ->whereHas('vehicle', fn ($query) => $query->whereIn('assigned_user_id', $technicianIds)->where('is_active', true))
            ->get()
            ->keyBy(fn (StockLocation $location) => $location->vehicle->assigned_user_id);
        $tomorrow = CarbonImmutable::tomorrow();
        $visits = Visit::query()->whereIn('assigned_user_id', $technicianIds)
            ->whereBetween('scheduled_start', [$tomorrow->startOfDay(), $tomorrow->endOfDay()])
            ->get(['id', 'assigned_user_id']);
        $visitIds = $visits->modelKeys();
        $visitOwners = $visits->pluck('assigned_user_id', 'id');
        $visitsByUser = $visits->groupBy('assigned_user_id');

        $reservedByUser = StockReservation::query()->whereIn('visit_id', $visitIds)->where('status', 'reserved')
            ->get(['visit_id', 'part_id', 'qty'])
            ->groupBy(fn (StockReservation $reservation) => $visitOwners->get($reservation->visit_id))
            ->map(fn (Collection $rows) => $rows->groupBy('part_id')->map->sum('qty'));
        $historyByUser = StockMove::query()
            ->join('visits as load_visits', 'load_visits.id', '=', 'stock_moves.visit_id')
            ->where('stock_moves.move_type', StockMove::VISIT_ISSUE)
            ->whereIn('load_visits.assigned_user_id', $technicianIds)
            ->where('load_visits.scheduled_start', '>=', now()->subDays(60))
            ->select(['load_visits.assigned_user_id', 'stock_moves.part_id'])
            ->selectRaw('SUM(stock_moves.qty) AS total_qty')
            ->selectRaw('COUNT(DISTINCT stock_moves.visit_id) AS visit_count')
            ->groupBy('load_visits.assigned_user_id', 'stock_moves.part_id')
            ->get()
            ->groupBy('assigned_user_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [
                (int) $row->part_id => (float) $row->total_qty / max(1, (int) $row->visit_count),
            ]));
        $partIds = $reservedByUser->flatMap->keys()->merge($historyByUser->flatMap->keys())->unique()->values()->all();
        $balances = $this->inventory->availableBalances($partIds, $locations->modelKeys());

        return $technicians->map(function (User $technician) use ($locations, $visitsByUser, $reservedByUser, $historyByUser, $balances) {
            $vehicleLocation = $locations->get($technician->id);
            $userVisits = $visitsByUser->get($technician->id, collect());
            if ($vehicleLocation === null || $userVisits->isEmpty()) {
                return null;
            }

            $reserved = $reservedByUser->get($technician->id, collect());
            $history = $historyByUser->get($technician->id, collect());
            $items = $reserved->keys()->merge($history->keys())->unique()->map(function ($partId) use ($reserved, $history, $vehicleLocation, $userVisits, $balances) {
                $needed = max((float) ($reserved[$partId] ?? 0), (float) ($history[$partId] ?? 0) * $userVisits->count());
                $onVehicle = $balances[$vehicleLocation->id][$partId] ?? 0.0;

                return ['part_id' => (int) $partId, 'suggested_qty' => round(max(0, $needed - $onVehicle), 3), 'on_vehicle' => $onVehicle];
            })->filter(fn ($row) => $row['suggested_qty'] > 0)->values();

            return ['technician' => $technician, 'location' => $vehicleLocation, 'visits_count' => $userVisits->count(), 'items' => $items];
        })->filter()->values();
    }
}

<?php

namespace App\Services;

use App\Models\OperatingBranch;
use App\Models\Part;
use App\Models\ReplenishmentRequest;
use App\Models\StockLocation;
use App\Support\BusinessReference;
use Illuminate\Support\Collection;

class ReplenishmentService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /** @return Collection<int, ReplenishmentRequest> */
    public function scan(): Collection
    {
        $created = collect();
        $parts = Part::with('suppliers')->where('is_active', true)->where('reorder_level', '>', 0)->get();
        $warehouses = StockLocation::where('type', StockLocation::TYPE_WAREHOUSE)->where('is_active', true)->get();
        // The scan runs with no signed-in user: a warehouse is only ever offered
        // its own company's suppliers.
        $branchCompanies = OperatingBranch::query()->whereIn('id', $warehouses->pluck('operating_branch_id')->filter()->unique())
            ->pluck('operating_company_id', 'id');
        $balances = $this->inventory->availableBalances($parts->modelKeys(), $warehouses->modelKeys());

        foreach ($warehouses as $location) {
            foreach ($parts as $part) {
                $current = $balances[$location->id][$part->id] ?? 0.0;
                if ($current >= (float) $part->reorder_level) {
                    continue;
                }
                $companyId = $branchCompanies->get($location->operating_branch_id);
                $supplier = $part->suppliers
                    ->filter(fn ($supplier) => $companyId !== null && (int) $supplier->operating_company_id === (int) $companyId)
                    ->sortByDesc(fn ($supplier) => (int) $supplier->pivot->is_preferred)
                    ->sortBy(fn ($supplier) => (float) $supplier->pivot->last_price)->first();
                $target = max((float) $part->reorder_level * 2, (float) $part->reorder_level + 1);
                $request = ReplenishmentRequest::firstOrCreate(
                    ['part_id' => $part->id, 'stock_location_id' => $location->id, 'status' => 'open'],
                    [
                        'request_no' => BusinessReference::make('RR'),
                        'suggested_supplier_id' => $supplier?->id,
                        'current_qty' => $current,
                        'target_qty' => $target,
                        'suggested_qty' => max(0, $target - $current),
                    ],
                );
                if ($request->wasRecentlyCreated) {
                    $created->push($request);
                    $this->audit->record('stock.replenishment_requested', $request, null, $request->toArray());
                } else {
                    $request->forceFill([
                        'current_qty' => $current, 'target_qty' => $target,
                        'suggested_qty' => max(0, $target - $current), 'suggested_supplier_id' => $supplier?->id,
                    ])->save();
                }
            }
        }

        return $created;
    }
}

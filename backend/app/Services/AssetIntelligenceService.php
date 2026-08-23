<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\KnowledgeArticle;
use App\Models\OperationalCost;
use App\Models\PartFailureReport;
use App\Models\StockMove;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

class AssetIntelligenceService
{
    /** @return Collection<int, array<string, mixed>> */
    public function assetHealth(): Collection
    {
        $assets = Asset::with('site.client')->get();
        $assetIds = $assets->modelKeys();
        if ($assetIds === []) {
            return collect();
        }

        $faults = WorkOrder::query()->whereIn('asset_id', $assetIds)
            ->select('asset_id')
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) AND reported_at >= ? THEN 1 ELSE 0 END) AS faults_90', ['reactive', 'out_of_contract', now()->subDays(90)])
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) AND reported_at >= ? THEN 1 ELSE 0 END) AS faults_365', ['reactive', 'out_of_contract', now()->subYear()])
            ->groupBy('asset_id')->get()->keyBy('asset_id');
        $partCosts = StockMove::query()
            ->join('visits', 'visits.id', '=', 'stock_moves.visit_id')
            ->join('work_orders', 'work_orders.id', '=', 'visits.work_order_id')
            ->whereIn('work_orders.asset_id', $assetIds)
            ->where('visits.scheduled_start', '>=', now()->subYear())
            ->whereIn('stock_moves.move_type', [StockMove::VISIT_ISSUE, StockMove::VISIT_RETURN])
            ->select('work_orders.asset_id')
            ->selectRaw('SUM(CASE WHEN stock_moves.move_type = ? THEN stock_moves.qty * stock_moves.unit_cost ELSE -stock_moves.qty * stock_moves.unit_cost END) AS total', [StockMove::VISIT_ISSUE])
            ->groupBy('work_orders.asset_id')->pluck('total', 'work_orders.asset_id');
        $otherCosts = OperationalCost::query()
            ->join('visits', 'visits.id', '=', 'operational_costs.visit_id')
            ->join('work_orders', 'work_orders.id', '=', 'visits.work_order_id')
            ->whereIn('work_orders.asset_id', $assetIds)
            ->where('visits.scheduled_start', '>=', now()->subYear())
            ->select('work_orders.asset_id')
            ->selectRaw('SUM(operational_costs.amount) AS total')
            ->groupBy('work_orders.asset_id')->pluck('total', 'work_orders.asset_id');

        return $assets->map(function (Asset $asset) use ($faults, $partCosts, $otherCosts) {
            $ageYears = $asset->installed_on?->diffInYears(now()) ?? 0;
            $faults90 = (int) ($faults->get($asset->id)?->faults_90 ?? 0);
            $faults365 = (int) ($faults->get($asset->id)?->faults_365 ?? 0);
            $partCost = (float) ($partCosts->get($asset->id) ?? 0);
            $otherCost = (float) ($otherCosts->get($asset->id) ?? 0);
            $cost = round(max(0, $partCost + $otherCost), 2);
            $costPenalty = $asset->replacement_value && (float) $asset->replacement_value > 0
                ? min(35, (int) round($cost / (float) $asset->replacement_value * 100)) : min(20, (int) floor($cost / 1000));
            $health = max(0, 100 - min(25, $ageYears * 2) - min(30, $faults90 * 10) - $costPenalty);
            $replace = $asset->replacement_value && $cost >= (float) $asset->replacement_value * .5;

            return compact('asset', 'ageYears', 'faults90', 'faults365', 'cost', 'health', 'replace');
        })->sortBy('health')->values();
    }

    /** @return Collection<int, object> */
    public function repeatedClientParts(): Collection
    {
        return StockMove::query()->join('visits as v', 'v.id', '=', 'stock_moves.visit_id')
            ->join('sites as s', 's.id', '=', 'v.site_id')->join('clients as c', 'c.id', '=', 's.client_id')
            ->join('parts as p', 'p.id', '=', 'stock_moves.part_id')->where('stock_moves.move_type', StockMove::VISIT_ISSUE)
            ->where('stock_moves.created_at', '>=', now()->subDays(90))
            ->selectRaw('c.id client_id, c.name client_name, p.id part_id, p.name part_name, COUNT(DISTINCT v.id) replacements')
            ->groupBy('c.id', 'c.name', 'p.id', 'p.name')->havingRaw('COUNT(DISTINCT v.id) >= 3')
            ->orderByDesc('replacements')->get();
    }

    /** @return Collection<int, object> */
    public function supplierFailures(): Collection
    {
        return PartFailureReport::query()->from('part_failure_reports as f')->join('suppliers as s', 's.id', '=', 'f.supplier_id')
            ->leftJoin('inventory_lots as l', function ($join) {
                $join->on('l.supplier_id', '=', 's.id')->where('l.created_at', '>=', now()->subYear());
            })->where('f.created_at', '>=', now()->subYear())
            ->selectRaw('s.id supplier_id, s.name supplier_name, COUNT(DISTINCT f.id) failures, COUNT(DISTINCT l.id) received_lots')
            ->groupBy('s.id', 's.name')->orderByDesc('failures')->get()
            ->map(function ($row) {
                $row->failure_rate = $row->received_lots > 0 ? round($row->failures / $row->received_lots * 100, 1) : null;

                return $row;
            });
    }

    /** @return array{ready:bool,completed:int,coded:int,minimum_completed:int,minimum_coded:int} */
    public function predictionReadiness(): array
    {
        $completed = Visit::where('state', Visit::STATE_COMPLETED)->count();
        $coded = Visit::where('state', Visit::STATE_COMPLETED)->whereHas('workOrder', fn ($q) => $q->whereNotNull('fault_code')->whereNotNull('diagnosis_code'))->count();

        return ['ready' => $completed >= 500 && $coded >= 100, 'completed' => $completed, 'coded' => $coded, 'minimum_completed' => 500, 'minimum_coded' => 100];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function diagnosisSuggestions(Asset $asset, ?string $faultCode = null): Collection
    {
        $articles = KnowledgeArticle::where('is_published', true)
            ->where(fn ($q) => $q->whereNull('asset_type')->orWhere('asset_type', $asset->type))
            ->when($faultCode, fn ($q) => $q->where(fn ($inner) => $inner->whereNull('fault_code')->orWhere('fault_code', $faultCode)))
            ->limit(10)->get()->map(fn ($article) => ['source' => 'knowledge', 'title' => $article->title, 'diagnosis' => $article->diagnosis, 'solution' => $article->solution, 'part_ids' => $article->suggested_part_ids ?? []]);
        $history = $asset->workOrders()->with('visits.stockMoves')->whereNotNull('diagnosis_code')->whereNotNull('resolution_summary')
            ->latest('reported_at')->limit(10)->get()->map(fn ($workOrder) => [
                'source' => 'history', 'title' => $workOrder->diagnosis_code, 'diagnosis' => $workOrder->description,
                'solution' => $workOrder->resolution_summary,
                'part_ids' => $workOrder->visits->flatMap->stockMoves->where('move_type', StockMove::VISIT_ISSUE)->pluck('part_id')->unique()->values()->all(),
            ]);

        return $articles->concat($history)->take(10)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function failurePredictions(): Collection
    {
        if (! $this->predictionReadiness()['ready']) {
            return collect();
        }

        return Asset::with(['site.client', 'workOrders' => fn ($q) => $q->whereNotNull('fault_code')->orderBy('reported_at')])->get()
            ->flatMap(function (Asset $asset) {
                return $asset->workOrders->groupBy('fault_code')->map(function ($orders, $faultCode) use ($asset) {
                    if ($orders->count() < 3) {
                        return null;
                    }
                    $dates = $orders->pluck('reported_at')->filter()->values();
                    $intervals = collect();
                    for ($index = 1; $index < $dates->count(); $index++) {
                        $intervals->push($dates[$index - 1]->diffInDays($dates[$index]));
                    }
                    $averageDays = max(1, (int) round($intervals->average()));

                    return [
                        'asset' => $asset, 'fault_code' => $faultCode, 'samples' => $orders->count(),
                        'average_days' => $averageDays, 'predicted_on' => $dates->last()->copy()->addDays($averageDays),
                        'confidence' => min(90, 45 + $orders->count() * 5),
                    ];
                })->filter();
            })->sortBy('predicted_on')->values();
    }
}

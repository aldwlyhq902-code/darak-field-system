<?php

namespace App\Services;

use App\Models\AdditionalWorkApproval;
use App\Models\Contract;
use App\Models\OperationalCost;
use App\Models\StockMove;
use App\Models\SubcontractorOrder;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProfitabilityService
{
    /** @param array<int, int> $visitIds
     * @param  array<int, float|int>  $revenues
     * @return array<int, array{revenue:float,parts:float,labor:float,subcontractors:float,other:float,cost:float,profit:float,margin_percent:?float}>
     */
    public function forVisitIds(array $visitIds, array $revenues = []): array
    {
        $visitIds = array_values(array_unique(array_map('intval', $visitIds)));
        if ($visitIds === []) {
            return [];
        }

        $parts = StockMove::query()->whereIn('visit_id', $visitIds)
            ->select('visit_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN move_type = 'VISIT_ISSUE' THEN qty * unit_cost WHEN move_type = 'VISIT_RETURN' THEN -qty * unit_cost ELSE 0 END), 0) AS total")
            ->groupBy('visit_id')->pluck('total', 'visit_id');
        $labor = Visit::query()->whereIn('visits.id', $visitIds)
            ->leftJoin('users', 'users.id', '=', 'visits.assigned_user_id')
            ->select('visits.id')
            ->selectRaw('COALESCE((visits.on_site_seconds / 3600.0) * COALESCE(users.hourly_cost, 0), 0) AS total')
            ->pluck('total', 'visits.id');
        $subcontractors = SubcontractorOrder::query()->whereIn('visit_id', $visitIds)
            ->where('status', '!=', 'cancelled')->select('visit_id')
            ->selectRaw('SUM(purchase_cost) AS total')->groupBy('visit_id')->pluck('total', 'visit_id');
        $other = OperationalCost::query()->whereIn('visit_id', $visitIds)
            ->select('visit_id')->selectRaw('SUM(amount) AS total')->groupBy('visit_id')->pluck('total', 'visit_id');

        $result = [];
        foreach ($visitIds as $visitId) {
            $revenue = round((float) ($revenues[$visitId] ?? 0), 2);
            $partCost = (float) ($parts->get($visitId) ?? 0);
            $laborCost = (float) ($labor->get($visitId) ?? 0);
            $subcontractorCost = (float) ($subcontractors->get($visitId) ?? 0);
            $otherCost = (float) ($other->get($visitId) ?? 0);
            $cost = round($partCost + $laborCost + $subcontractorCost + $otherCost, 2);
            $profit = round($revenue - $cost, 2);
            $result[$visitId] = [
                'revenue' => $revenue, 'parts' => $partCost, 'labor' => $laborCost,
                'subcontractors' => $subcontractorCost, 'other' => $otherCost,
                'cost' => $cost, 'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            ];
        }

        return $result;
    }

    /** @param Collection<int, Visit> $visits
     * @return array<int, array<string, float|null>>
     */
    public function forVisitModels(Collection $visits): array
    {
        $ids = $visits->modelKeys();
        if ($ids === []) {
            return [];
        }
        $additional = AdditionalWorkApproval::query()->whereIn('visit_id', $ids)->where('status', 'approved')
            ->select('visit_id')->selectRaw('SUM(amount) AS total')->groupBy('visit_id')->pluck('total', 'visit_id');
        $subcontractorSales = SubcontractorOrder::query()->whereIn('visit_id', $ids)->where('status', '!=', 'cancelled')
            ->select('visit_id')->selectRaw('SUM(sale_price) AS total')->groupBy('visit_id')->pluck('total', 'visit_id');
        $revenues = [];
        foreach ($ids as $id) {
            $revenues[$id] = (float) ($additional->get($id) ?? 0) + (float) ($subcontractorSales->get($id) ?? 0);
        }

        return $this->forVisitIds($ids, $revenues);
    }

    /** @param Collection<int, Contract> $contracts
     * @return array<int, array<string, float|null>>
     */
    public function forContracts(Collection $contracts): array
    {
        $contractIds = $contracts->modelKeys();
        if ($contractIds === []) {
            return [];
        }
        $visits = Visit::query()->join('work_orders', 'work_orders.id', '=', 'visits.work_order_id')
            ->whereIn('work_orders.contract_id', $contractIds)
            ->get(['visits.id', 'work_orders.contract_id']);
        $visitCosts = $this->forVisitIds($visits->pluck('id')->all());
        $contractOnlyCosts = OperationalCost::query()->whereIn('contract_id', $contractIds)->whereNull('visit_id')
            ->select('contract_id')->selectRaw('SUM(amount) AS total')->groupBy('contract_id')->pluck('total', 'contract_id');
        $visitsByContract = $visits->groupBy('contract_id');
        $result = [];

        foreach ($contracts as $contract) {
            $summary = ['parts' => 0.0, 'labor' => 0.0, 'subcontractors' => 0.0, 'other' => (float) ($contractOnlyCosts->get($contract->id) ?? 0)];
            foreach ($visitsByContract->get($contract->id, collect()) as $visit) {
                $cost = $visitCosts[$visit->id] ?? null;
                if ($cost === null) {
                    continue;
                }
                foreach (array_keys($summary) as $key) {
                    $summary[$key] += (float) $cost[$key];
                }
            }
            $revenue = (float) $contract->installments->sum('amount');
            $totalCost = round(array_sum($summary), 2);
            $profit = round($revenue - $totalCost, 2);
            $result[$contract->id] = [
                'revenue' => $revenue, ...$summary, 'cost' => $totalCost, 'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            ];
        }

        return $result;
    }

    /** @param Collection<int, User> $users
     * @return array<int, array<string, float|null>>
     */
    public function forTechnicians(Collection $users, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $visits = Visit::query()->whereIn('assigned_user_id', $users->modelKeys())
            ->whereBetween('scheduled_start', [$from, $to])->get(['id', 'assigned_user_id']);
        $costs = $this->forVisitIds($visits->pluck('id')->all());

        return $users->mapWithKeys(function (User $user) use ($visits, $costs): array {
            $summary = ['revenue' => 0.0, 'parts' => 0.0, 'labor' => 0.0, 'subcontractors' => 0.0, 'other' => 0.0];
            foreach ($visits->where('assigned_user_id', $user->id) as $visit) {
                foreach (['parts', 'labor', 'subcontractors', 'other'] as $key) {
                    $summary[$key] += (float) ($costs[$visit->id][$key] ?? 0);
                }
            }
            $cost = round($summary['parts'] + $summary['labor'] + $summary['subcontractors'] + $summary['other'], 2);

            return [$user->id => $summary + ['cost' => $cost, 'profit' => -$cost, 'margin_percent' => null]];
        })->all();
    }

    /** @return array{revenue:float,parts:float,labor:float,subcontractors:float,other:float,cost:float,profit:float,margin_percent:?float} */
    public function forVisits(Builder $visits, float $revenue): array
    {
        $ids = (clone $visits)->pluck('visits.id');
        $parts = (float) StockMove::query()->whereIn('visit_id', $ids)
            ->selectRaw("COALESCE(SUM(CASE WHEN move_type = 'VISIT_ISSUE' THEN qty * unit_cost WHEN move_type = 'VISIT_RETURN' THEN -qty * unit_cost ELSE 0 END), 0) AS total")
            ->value('total');
        $labor = (float) Visit::query()->whereIn('visits.id', $ids)
            ->leftJoin('users', 'users.id', '=', 'visits.assigned_user_id')
            ->selectRaw('COALESCE(SUM((visits.on_site_seconds / 3600.0) * COALESCE(users.hourly_cost, 0)), 0) AS total')
            ->value('total');
        $subcontractors = (float) SubcontractorOrder::whereIn('visit_id', $ids)->where('status', '!=', 'cancelled')->sum('purchase_cost');
        $other = (float) OperationalCost::whereIn('visit_id', $ids)->sum('amount');
        $cost = round($parts + $labor + $subcontractors + $other, 2);
        $revenue = round($revenue, 2);
        $profit = round($revenue - $cost, 2);

        return compact('revenue', 'parts', 'labor', 'subcontractors', 'other', 'cost', 'profit') + [
            'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }

    public function contract(Contract $contract): array
    {
        $revenue = (float) $contract->installments()->sum('amount');
        $visits = Visit::query()->whereHas('workOrder', fn ($q) => $q->where('contract_id', $contract->id));
        $result = $this->forVisits($visits, $revenue);
        $result['other'] += (float) OperationalCost::where('contract_id', $contract->id)->whereNull('visit_id')->sum('amount');
        $result['cost'] = round($result['parts'] + $result['labor'] + $result['subcontractors'] + $result['other'], 2);
        $result['profit'] = round($result['revenue'] - $result['cost'], 2);
        $result['margin_percent'] = $result['revenue'] > 0 ? round($result['profit'] / $result['revenue'] * 100, 1) : null;

        return $result;
    }

    public function visit(Visit $visit): array
    {
        $revenue = (float) $visit->additionalWorkApprovals()->where('status', 'approved')->sum('amount')
            + (float) SubcontractorOrder::where('visit_id', $visit->id)->where('status', '!=', 'cancelled')->sum('sale_price');

        return $this->forVisits(Visit::query()->whereKey($visit->id), $revenue);
    }

    public function technician(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $visits = Visit::query()->where('assigned_user_id', $user->id)->whereBetween('scheduled_start', [$from, $to]);

        return $this->forVisits($visits, 0);
    }
}

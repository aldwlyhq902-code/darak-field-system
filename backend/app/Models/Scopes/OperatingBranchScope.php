<?php

namespace App\Models\Scopes;

use App\Models\OperatingBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Tenant boundary for branch operators and branch-less company operators. */
class OperatingBranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = request()->user() ?? auth()->user();
        // Client-portal identities have their own client-specific authorization.
        // This scope governs only internal workforce users.
        if (! $user instanceof User || $user->isPlatformAdmin()) {
            return;
        }

        $branchId = $user->operating_branch_id ? (int) $user->operating_branch_id : null;
        $companyId = $user->operating_company_id ? (int) $user->operating_company_id : null;
        if ($branchId !== null) {
            $branchCompanyId = OperatingBranch::query()->whereKey($branchId)->value('operating_company_id');
            if ($branchCompanyId === null || ($companyId !== null && (int) $branchCompanyId !== $companyId)) {
                $builder->whereRaw('1 = 0');

                return;
            }
            $companyId ??= (int) $branchCompanyId;
        }
        // Missing tenancy is never an implicit super-admin grant.
        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $table = $model->getTable();
        $column = fn (string $name): string => $model->qualifyColumn($name);

        if ($table === 'clients') {
            $builder->where($column('operating_company_id'), $companyId)
                ->when($branchId !== null, fn ($query) => $query->where($column('operating_branch_id'), $branchId));

            return;
        }
        if (in_array($table, [
            'vehicles', 'stock_locations', 'employee_documents', 'employee_leaves',
            'vehicle_documents', 'vehicle_maintenance_orders', 'vehicle_inspections', 'sales_leads',
        ], true)) {
            $this->throughBranch($builder, $column('operating_branch_id'), $companyId, $branchId);

            return;
        }
        if (in_array($table, [
            'contracts', 'quotations', 'payments', 'work_orders', 'additional_work_approvals',
            'maintenance_plans', 'client_service_requests',
        ], true)) {
            $this->throughClient($builder, $column('client_id'), $companyId, $branchId);

            return;
        }
        if ($table === 'sites') {
            $this->throughClient($builder, $column('client_id'), $companyId, $branchId);

            return;
        }
        if (in_array($table, ['visits', 'assets', 'emergency_reports'], true)) {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('sites as tenant_sites')
                    ->join('clients as tenant_clients', 'tenant_clients.id', '=', 'tenant_sites.client_id')
                    ->whereColumn('tenant_sites.id', $column('site_id'))
                    ->where('tenant_clients.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_clients.operating_branch_id', $branchId));
            });

            return;
        }
        if ($table === 'contract_installments') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('contracts as tenant_contracts')
                    ->join('clients as tenant_clients', 'tenant_clients.id', '=', 'tenant_contracts.client_id')
                    ->whereColumn('tenant_contracts.id', $column('contract_id'))
                    ->where('tenant_clients.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_clients.operating_branch_id', $branchId));
            });

            return;
        }
        if (in_array($table, ['purchase_orders', 'request_for_quotations'], true)) {
            $this->throughLocation($builder, $column('destination_location_id'), $companyId, $branchId);

            return;
        }
        if ($table === 'purchase_order_items') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('purchase_orders as tenant_orders')
                    ->join('stock_locations as tenant_locations', 'tenant_locations.id', '=', 'tenant_orders.destination_location_id')
                    ->join('operating_branches as tenant_branches', 'tenant_branches.id', '=', 'tenant_locations.operating_branch_id')
                    ->whereColumn('tenant_orders.id', $column('purchase_order_id'))
                    ->where('tenant_branches.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_locations.operating_branch_id', $branchId));
            });

            return;
        }
        if (in_array($table, ['inventory_lots', 'replenishment_requests', 'stock_reservations', 'stocktake_sessions'], true)) {
            $this->throughLocation($builder, $column('stock_location_id'), $companyId, $branchId);

            return;
        }
        if ($table === 'vehicle_stock_transfers') {
            $this->throughLocation($builder, $column('from_location_id'), $companyId, $branchId);

            return;
        }
        if ($table === 'supplier_quotations') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('request_for_quotations as tenant_rfqs')
                    ->join('stock_locations as tenant_locations', 'tenant_locations.id', '=', 'tenant_rfqs.destination_location_id')
                    ->join('operating_branches as tenant_branches', 'tenant_branches.id', '=', 'tenant_locations.operating_branch_id')
                    ->whereColumn('tenant_rfqs.id', $column('request_for_quotation_id'))
                    ->where('tenant_branches.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_locations.operating_branch_id', $branchId));
            });

            return;
        }
        if ($table === 'vehicle_expenses') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('vehicles as tenant_vehicles')
                    ->join('operating_branches as tenant_branches', 'tenant_branches.id', '=', 'tenant_vehicles.operating_branch_id')
                    ->whereColumn('tenant_vehicles.id', $column('vehicle_id'))
                    ->where('tenant_branches.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_vehicles.operating_branch_id', $branchId));
            });

            return;
        }
        if ($table === 'custodies') {
            $builder->whereExists(fn ($query) => $query->selectRaw('1')->from('users as tenant_users')
                ->whereColumn('tenant_users.id', $column('user_id'))
                ->where('tenant_users.operating_company_id', $companyId)
                ->when($branchId !== null, fn ($q) => $q->where('tenant_users.operating_branch_id', $branchId)));

            return;
        }
        if ($table === 'subcontractor_orders') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('work_orders as tenant_work_orders')
                    ->join('clients as tenant_clients', 'tenant_clients.id', '=', 'tenant_work_orders.client_id')
                    ->whereColumn('tenant_work_orders.id', $column('work_order_id'))
                    ->where('tenant_clients.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_clients.operating_branch_id', $branchId));
            });

            return;
        }
        if ($table === 'sales_lead_attachments') {
            $builder->whereExists(function ($query) use ($column, $branchId, $companyId): void {
                $query->selectRaw('1')->from('sales_leads as tenant_leads')
                    ->join('operating_branches as tenant_branches', 'tenant_branches.id', '=', 'tenant_leads.operating_branch_id')
                    ->whereColumn('tenant_leads.id', $column('sales_lead_id'))
                    ->where('tenant_branches.operating_company_id', $companyId)
                    ->when($branchId !== null, fn ($q) => $q->where('tenant_leads.operating_branch_id', $branchId));
            });

            return;
        }
        if ($table === 'stock_moves') {
            $builder->where(function (Builder $scope) use ($column, $branchId, $companyId): void {
                $scope->whereExists(fn ($query) => $this->locationSubquery($query, $column('from_location_id'), $companyId, $branchId))
                    ->orWhereExists(fn ($query) => $this->locationSubquery($query, $column('to_location_id'), $companyId, $branchId))
                    ->orWhereExists(function ($query) use ($column, $branchId, $companyId): void {
                        $query->selectRaw('1')->from('visits as tenant_visits')
                            ->join('sites as tenant_sites', 'tenant_sites.id', '=', 'tenant_visits.site_id')
                            ->join('clients as tenant_clients', 'tenant_clients.id', '=', 'tenant_sites.client_id')
                            ->whereColumn('tenant_visits.id', $column('visit_id'))
                            ->where('tenant_clients.operating_company_id', $companyId)
                            ->when($branchId !== null, fn ($q) => $q->where('tenant_clients.operating_branch_id', $branchId));
                    });
            });
        }
    }

    private function throughBranch(Builder $builder, string $branchColumn, int $companyId, ?int $branchId): void
    {
        if ($branchId !== null) {
            $builder->where($branchColumn, $branchId);

            return;
        }
        $builder->whereExists(fn ($query) => $query->selectRaw('1')->from('operating_branches as tenant_branches')
            ->whereColumn('tenant_branches.id', $branchColumn)
            ->where('tenant_branches.operating_company_id', $companyId));
    }

    private function throughClient(Builder $builder, string $clientColumn, int $companyId, ?int $branchId): void
    {
        $builder->whereExists(fn ($query) => $query->selectRaw('1')->from('clients as tenant_clients')
            ->whereColumn('tenant_clients.id', $clientColumn)
            ->where('tenant_clients.operating_company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('tenant_clients.operating_branch_id', $branchId)));
    }

    private function throughLocation(Builder $builder, string $locationColumn, int $companyId, ?int $branchId): void
    {
        $builder->whereExists(fn ($query) => $this->locationSubquery($query, $locationColumn, $companyId, $branchId));
    }

    private function locationSubquery($query, string $locationColumn, int $companyId, ?int $branchId): void
    {
        $query->selectRaw('1')->from('stock_locations as tenant_locations')
            ->join('operating_branches as tenant_branches', 'tenant_branches.id', '=', 'tenant_locations.operating_branch_id')
            ->whereColumn('tenant_locations.id', $locationColumn)
            ->where('tenant_branches.operating_company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('tenant_locations.operating_branch_id', $branchId));
    }
}

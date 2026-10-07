<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rows of the branch-keyed tables written with no branch.
 *
 * OperatingBranchScope shows vehicles, stock locations, employee documents and
 * leaves, vehicle documents, maintenance orders and inspections and sales leads
 * (and, through the lead, its attachments) only through their branch. When a
 * company-wide user (no branch — the owner) created one, it was written with
 * operating_branch_id NULL and vanished for everyone but platform admins.
 * New rows now always get a branch (App\Support\BranchAssignment); this
 * repairs the existing ones where the answer is unambiguous:
 *
 *  1. the branch of the record it hangs off — the vehicle (documents, orders,
 *     inspections, its stock location), the employee (documents, leaves), the
 *     lead's owner or converted client — exactly as a new row would be filed;
 *  2. otherwise the company, derived from the related records (assigned user,
 *     author, employee, vehicle, owner, client; or the only company there is),
 *     when that company has exactly one active branch.
 *
 * Anything else — no derivable company, related records in several companies,
 * or a company with several active branches — is left untouched and counted
 * in the output, for a person to assign (Administration > assign branch).
 */
return new class extends Migration
{
    /** @var array<int, list<int>> active branch ids per company */
    private array $activeBranches = [];

    private ?int $onlyCompany = null;

    public function up(): void
    {
        if (! Schema::hasTable('operating_branches')) {
            return;
        }

        $this->activeBranches = DB::table('operating_branches')->where('is_active', true)->orderBy('id')
            ->get(['id', 'operating_company_id'])
            ->groupBy('operating_company_id')
            ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->all();
        $companies = DB::table('operating_companies')->pluck('id');
        $this->onlyCompany = $companies->count() === 1 ? (int) $companies->first() : null;

        $user = fn (?int $id): array => $id === null ? [null, null] : $this->userTenancy($id);
        $vehicle = fn (?int $id): array => $id === null ? [null, null] : $this->vehicleTenancy($id);

        // Vehicles first: their children inherit from them.
        $this->repair('vehicles', fn ($row) => [
            'branch' => $user($row->assigned_user_id)[0],
            'companies' => array_merge(
                [$user($row->assigned_user_id)[1]],
                $this->authorCompanies('vehicle_documents', 'vehicle_id', 'created_by', $row->id),
                $this->authorCompanies('vehicle_maintenance_orders', 'vehicle_id', 'created_by', $row->id),
                $this->authorCompanies('vehicle_inspections', 'vehicle_id', 'inspected_by', $row->id),
            ),
        ], ['id', 'assigned_user_id']);

        $this->repair('stock_locations', fn ($row) => [
            'branch' => $vehicle($row->vehicle_id)[0],
            'companies' => [$vehicle($row->vehicle_id)[1]],
        ], ['id', 'vehicle_id']);

        foreach ([
            'vehicle_documents' => 'created_by',
            'vehicle_maintenance_orders' => 'created_by',
            'vehicle_inspections' => 'inspected_by',
        ] as $table => $author) {
            $this->repair($table, fn ($row) => [
                'branch' => $vehicle($row->vehicle_id)[0],
                'companies' => [$vehicle($row->vehicle_id)[1], $user($row->{$author})[1]],
            ], ['id', 'vehicle_id', $author]);
        }

        foreach (['employee_documents' => 'created_by', 'employee_leaves' => 'requested_by'] as $table => $author) {
            $this->repair($table, fn ($row) => [
                'branch' => $user($row->user_id)[0],
                'companies' => [$user($row->user_id)[1], $user($row->{$author})[1]],
            ], ['id', 'user_id', $author]);
        }

        $this->repair('sales_leads', function ($row) use ($user): array {
            $client = $row->converted_client_id === null ? null
                : DB::table('clients')->where('id', $row->converted_client_id)->first(['operating_branch_id', 'operating_company_id']);

            return [
                'branch' => $user($row->owner_user_id)[0] ?? $this->int($client?->operating_branch_id),
                'companies' => [$user($row->owner_user_id)[1], $this->int($client?->operating_company_id)],
            ];
        }, ['id', 'owner_user_id', 'converted_client_id']);
    }

    /** Irreversible by nature: which rows were branchless is not recorded. */
    public function down(): void {}

    /**
     * @param  Closure(object): array{branch: ?int, companies: list<?int>}  $resolve
     * @param  list<string>  $columns
     */
    private function repair(string $table, Closure $resolve, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'operating_branch_id')) {
            return;
        }

        $assigned = 0;
        $ambiguous = 0;
        DB::table($table)->whereNull('operating_branch_id')->orderBy('id')->select($columns)
            ->chunkById(500, function ($rows) use ($table, $resolve, &$assigned, &$ambiguous): void {
                foreach ($rows as $row) {
                    $found = $resolve($row);
                    $branchId = $found['branch'] ?? $this->singleBranchOf($found['companies']);

                    if ($branchId === null) {
                        $ambiguous++;

                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->whereNull('operating_branch_id')
                        ->update(['operating_branch_id' => $branchId]);
                    $assigned++;
                }
            });

        if ($assigned + $ambiguous > 0) {
            $this->report("{$table}: {$assigned} assigned to a branch, {$ambiguous} left without a branch (ambiguous — assign them manually).");
        }
    }

    /** @param  list<?int>  $candidates */
    private function singleBranchOf(array $candidates): ?int
    {
        $companies = array_values(array_unique(array_filter($candidates, fn ($id) => $id !== null)));
        $companyId = match (count($companies)) {
            0 => $this->onlyCompany,
            1 => $companies[0],
            default => null,
        };
        $branches = $companyId === null ? [] : ($this->activeBranches[$companyId] ?? []);

        return count($branches) === 1 ? $branches[0] : null;
    }

    /** @return array{0: ?int, 1: ?int} [branch, company] */
    private function userTenancy(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['operating_branch_id', 'operating_company_id']);

        return [$this->int($user?->operating_branch_id), $this->int($user?->operating_company_id)];
    }

    /** @return array{0: ?int, 1: ?int} [branch, company] — read after vehicles were repaired */
    private function vehicleTenancy(int $vehicleId): array
    {
        $vehicle = DB::table('vehicles')->where('id', $vehicleId)->first(['operating_branch_id', 'assigned_user_id']);
        if ($vehicle === null) {
            return [null, null];
        }
        $branchId = $this->int($vehicle->operating_branch_id);
        $companyId = $branchId === null
            ? ($vehicle->assigned_user_id === null ? null : $this->userTenancy((int) $vehicle->assigned_user_id)[1])
            : $this->int(DB::table('operating_branches')->where('id', $branchId)->value('operating_company_id'));

        return [$branchId, $companyId];
    }

    /** @return list<?int> companies of the users who wrote a vehicle's child records */
    private function authorCompanies(string $table, string $foreignKey, string $authorColumn, int $id): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->join('users', 'users.id', '=', "{$table}.{$authorColumn}")
            ->where("{$table}.{$foreignKey}", $id)->distinct()
            ->pluck('users.operating_company_id')->map(fn ($id) => $this->int($id))->all();
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private bool $reported = false;

    private function report(string $line): void
    {
        Log::info('operating-branch backfill: '.$line);
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            // The migrator has already printed this migration's name on the line.
            fwrite(STDOUT, ($this->reported ? '' : PHP_EOL).'  operating-branch backfill — '.$line.PHP_EOL);
            $this->reported = true;
        }
    }
};

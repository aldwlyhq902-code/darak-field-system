<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part failure reports belong to the reporting company. Supplier-quality
 * figures used to count every company's failures and received lots, exposing
 * other companies' procurement volume.
 *
 * Backfill: the company of the asset, visit or lot the report names, else the
 * reporter's company, else the only company. Anything left stays company-less —
 * visible only to platform admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_failure_reports', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['operating_company_id', 'created_at']);
        });

        $sources = [
            'asset_id' => '(SELECT clients.operating_company_id FROM assets JOIN sites ON sites.id = assets.site_id JOIN clients ON clients.id = sites.client_id WHERE assets.id = part_failure_reports.asset_id)',
            'visit_id' => '(SELECT clients.operating_company_id FROM visits JOIN sites ON sites.id = visits.site_id JOIN clients ON clients.id = sites.client_id WHERE visits.id = part_failure_reports.visit_id)',
            'inventory_lot_id' => '(SELECT operating_branches.operating_company_id FROM inventory_lots JOIN stock_locations ON stock_locations.id = inventory_lots.stock_location_id JOIN operating_branches ON operating_branches.id = stock_locations.operating_branch_id WHERE inventory_lots.id = part_failure_reports.inventory_lot_id)',
            'reported_by' => '(SELECT operating_company_id FROM users WHERE users.id = part_failure_reports.reported_by)',
        ];
        foreach ($sources as $column => $company) {
            DB::table('part_failure_reports')->whereNull('operating_company_id')->whereNotNull($column)
                ->update(['operating_company_id' => DB::raw($company)]);
        }
        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('part_failure_reports')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);
        }
    }

    public function down(): void
    {
        Schema::table('part_failure_reports', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'created_at']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};

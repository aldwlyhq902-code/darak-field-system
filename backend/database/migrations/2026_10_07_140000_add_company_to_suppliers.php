<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier directory (VAT numbers, contacts, notes) and its negotiated part
 * prices (supplier_parts, which inherit the supplier's company) belong to one
 * company. Every company used to read them, and could overwrite another
 * company's negotiated price or preferred supplier.
 *
 * Backfill: with exactly one company every supplier is that company's.
 * Otherwise a supplier whose purchase orders, quotations and received lots all
 * land in one company's stock locations is that company's; any other supplier
 * stays company-less — visible only to platform admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['operating_company_id', 'is_active']);
        });

        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('suppliers')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);

            return;
        }

        $usage = fn (string $table, string $locationColumn) => DB::table($table)
            ->join('stock_locations', 'stock_locations.id', '=', "{$table}.{$locationColumn}")
            ->join('operating_branches', 'operating_branches.id', '=', 'stock_locations.operating_branch_id')
            ->whereNotNull("{$table}.supplier_id")
            ->select("{$table}.supplier_id as supplier_id", 'operating_branches.operating_company_id as company_id');
        $quotations = DB::table('supplier_quotations')
            ->join('request_for_quotations', 'request_for_quotations.id', '=', 'supplier_quotations.request_for_quotation_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'request_for_quotations.destination_location_id')
            ->join('operating_branches', 'operating_branches.id', '=', 'stock_locations.operating_branch_id')
            ->select('supplier_quotations.supplier_id as supplier_id', 'operating_branches.operating_company_id as company_id');
        $rows = $usage('purchase_orders', 'destination_location_id')->get()
            ->concat($usage('inventory_lots', 'stock_location_id')->get())
            ->concat($quotations->get());

        $rows->groupBy('supplier_id')
            ->map(fn ($uses) => $uses->pluck('company_id')->unique()->values())
            ->filter(fn ($companies) => $companies->count() === 1)
            ->each(fn ($companies, $supplierId) => DB::table('suppliers')->where('id', $supplierId)
                ->whereNull('operating_company_id')->update(['operating_company_id' => $companies->first()]));
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'is_active']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};

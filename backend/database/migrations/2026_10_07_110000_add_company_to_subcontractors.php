<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The subcontractor directory belongs to one company. A shared directory showed
 * every company another company's partners, contacts, prices and documents.
 *
 * Backfill: with exactly one company every partner is that company's. Otherwise
 * a partner whose orders all sit on one company's work orders is that company's;
 * any other partner (no orders, or orders for several companies) stays
 * company-less — a platform default only platform admins see, until it is filed
 * again for a specific company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontractors', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['operating_company_id', 'is_active']);
        });

        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('subcontractors')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);

            return;
        }

        DB::table('subcontractor_orders')
            ->join('work_orders', 'work_orders.id', '=', 'subcontractor_orders.work_order_id')
            ->join('clients', 'clients.id', '=', 'work_orders.client_id')
            ->whereNotNull('clients.operating_company_id')
            ->groupBy('subcontractor_orders.subcontractor_id')
            ->havingRaw('COUNT(DISTINCT clients.operating_company_id) = 1')
            ->selectRaw('subcontractor_orders.subcontractor_id AS partner_id, MIN(clients.operating_company_id) AS company_id')
            ->get()
            ->each(fn ($row) => DB::table('subcontractors')->where('id', $row->partner_id)
                ->whereNull('operating_company_id')->update(['operating_company_id' => $row->company_id]));
    }

    public function down(): void
    {
        Schema::table('subcontractors', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'is_active']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};

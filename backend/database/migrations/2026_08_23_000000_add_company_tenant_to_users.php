<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('operating_branch_id')
                ->constrained()->restrictOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('operating_company_id');
            $table->index(['operating_company_id', 'operating_branch_id']);
        });

        DB::table('users')->whereNotNull('operating_branch_id')->update([
            'operating_company_id' => DB::raw('(SELECT operating_company_id FROM operating_branches WHERE operating_branches.id = users.operating_branch_id)'),
        ]);

        // A branch-less legacy administrator is safely attributable only when the
        // installation has exactly one company. Ambiguous users remain tenant-less
        // and the runtime scope denies their access until an administrator assigns one.
        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            $companyId = $companyIds->first();
            DB::table('users')->whereNull('operating_company_id')->update([
                'operating_company_id' => $companyId,
            ]);
            DB::table('clients')->whereNull('operating_company_id')->update([
                'operating_company_id' => $companyId,
            ]);

            $branchIds = DB::table('operating_branches')
                ->where('operating_company_id', $companyId)
                ->pluck('id');
            if ($branchIds->count() === 1) {
                $branchId = $branchIds->first();
                DB::table('clients')->whereNull('operating_branch_id')->update(['operating_branch_id' => $branchId]);
                DB::table('stock_locations')->whereNull('operating_branch_id')->update(['operating_branch_id' => $branchId]);
                DB::table('vehicles')->whereNull('operating_branch_id')->update(['operating_branch_id' => $branchId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'operating_branch_id']);
            $table->dropConstrainedForeignId('operating_company_id');
            $table->dropColumn('is_platform_admin');
        });
    }
};

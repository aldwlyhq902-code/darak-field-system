<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission policies belong to one company. A global policy used to pay every
 * company's people and was visible to (and usable by) every company's owner.
 *
 * Backfill: a legacy rule is safely attributable only when the installation has
 * exactly one company. Otherwise it stays company-less — a platform default that
 * only platform admins see, and that generates nothing until it is filed again
 * for a specific company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['operating_company_id', 'is_active']);
        });

        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('commission_rules')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);
        }
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'is_active']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};

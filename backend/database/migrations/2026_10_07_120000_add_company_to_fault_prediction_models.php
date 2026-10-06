<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The failure-prediction model is trained, stored and applied per company. One
 * model used to learn from every company's history and score every asset.
 *
 * Backfill: with exactly one company its models are that company's. Otherwise
 * the existing models mixed companies' histories: they stay company-less and
 * are deactivated, so each company starts from its own next training run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_prediction_models', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['operating_company_id', 'is_active']);
        });

        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('fault_prediction_models')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);

            return;
        }
        DB::table('fault_prediction_models')->whereNull('operating_company_id')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('fault_prediction_models', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'is_active']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};

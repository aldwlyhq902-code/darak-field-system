<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance weights and targets are set per company. One global table let
 * any company's owner rewrite the scoring of every other company.
 *
 * Existing rows become the platform defaults (operating_company_id null) and
 * keep their current values, so nothing changes until a company saves its own
 * settings: that save writes company rows that override the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_metric_settings', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['category', 'metric_key']);
            $table->unique(['operating_company_id', 'category', 'metric_key']);
        });
        // NULLs are distinct in a unique index: keep the defaults unique too.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX performance_metric_settings_default_unique ON performance_metric_settings (category, metric_key) WHERE operating_company_id IS NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS performance_metric_settings_default_unique');
        }
        DB::table('performance_metric_settings')->whereNotNull('operating_company_id')->delete();
        Schema::table('performance_metric_settings', function (Blueprint $table): void {
            $table->dropUnique(['operating_company_id', 'category', 'metric_key']);
            $table->dropConstrainedForeignId('operating_company_id');
            $table->unique(['category', 'metric_key']);
        });
    }
};

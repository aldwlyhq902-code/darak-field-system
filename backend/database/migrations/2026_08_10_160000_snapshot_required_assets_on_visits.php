<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which assets this visit is required to cover, frozen when it is created.
 *
 * CloseGate checked only the checklist instances the CLIENT chose to open, so a
 * technician could inspect one unit, skip the other five, and close a visit that
 * the report would then present as a completed preventive round. The gate was
 * asking "did you finish what you started?" instead of "did you do the job?".
 *
 * A snapshot rather than a live query: assets get added to a site over time, and
 * a visit must be judged against what it was scheduled to cover, not against
 * whatever exists on the day someone opens the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->json('required_asset_ids')->nullable()->after('site_id');
        });

        // Backfill open visits from their site's current assets so existing rows
        // are not left with an empty requirement that passes trivially.
        foreach (DB::table('visits')->whereNull('required_asset_ids')->get(['id', 'site_id']) as $visit) {
            $assetIds = DB::table('assets')
                ->where('site_id', $visit->site_id)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            DB::table('visits')
                ->where('id', $visit->id)
                ->update(['required_asset_ids' => json_encode($assetIds)]);
        }
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('required_asset_ids');
        });
    }
};

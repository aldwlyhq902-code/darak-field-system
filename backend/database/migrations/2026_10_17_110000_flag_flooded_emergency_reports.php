<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site past its report cap no longer refuses reports — anyone holding a
 * photo of the sticker could otherwise silence a genuine gas or cold-room
 * report for a day from a handful of addresses. Past the cap a report is kept
 * but flagged, carries no photo, and raises one summary alert per site-hour
 * instead of one alert each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->boolean('flood_flagged')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->dropColumn('flood_flagged');
        });
    }
};

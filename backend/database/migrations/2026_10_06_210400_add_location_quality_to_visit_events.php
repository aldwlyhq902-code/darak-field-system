<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location evidence the supervisor can weigh: how precise the fix was, whether
 * the OS reported a mock provider, and whether it fell outside the site fence.
 * Recorded, surfaced, never used to refuse the event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_events', function (Blueprint $table) {
            $table->decimal('accuracy_m', 9, 2)->nullable()->after('lng');
            $table->boolean('is_mocked')->nullable()->after('accuracy_m');
            $table->boolean('outside_geofence')->nullable()->after('is_mocked');
        });
    }

    public function down(): void
    {
        Schema::table('visit_events', function (Blueprint $table) {
            $table->dropColumn(['accuracy_m', 'is_mocked', 'outside_geofence']);
        });
    }
};

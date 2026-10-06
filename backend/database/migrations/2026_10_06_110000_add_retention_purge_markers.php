<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * darak:retention-purge removes the bytes of expired evidence but keeps the row
 * (kind, hash, timestamps) so the visit history still shows that evidence
 * existed. purged_at distinguishes "destroyed under the retention policy" from
 * "never uploaded", which a null path alone cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media_files', 'emergency_reports'] as $table) {
            if (! Schema::hasColumn($table, 'purged_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->timestamp('purged_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['media_files', 'emergency_reports'] as $table) {
            if (Schema::hasColumn($table, 'purged_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('purged_at'));
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The photo now records WHICH ASSET it is of, directly.
 *
 * Before this, the app sent `asset_id` and the server stored only
 * `checklist_instance_id` — which it never received, so every photo was filed
 * against no asset at all and CloseGate's per-asset requirement could never be
 * satisfied. A visit was uncloseable through the real client.
 *
 * asset_id rather than checklist_instance_id is the durable key because it is
 * ORDER-INDEPENDENT: an offline batch may deliver the photo before the checklist
 * event that would have created the instance, and the device has never seen a
 * server-side instance id in any case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('checklist_instance_id')
                ->constrained()->nullOnDelete();
            $table->index(['visit_id', 'asset_id']);
        });

        // Backfill anything already filed against a checklist instance.
        DB::statement('
            UPDATE media_files
               SET asset_id = (
                   SELECT asset_id FROM checklist_instances
                    WHERE checklist_instances.id = media_files.checklist_instance_id
               )
             WHERE checklist_instance_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(['visit_id', 'asset_id']);
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence bytes may only be written by the technician device that registered
 * the file. Viewing a visit (which back office may do) is not the same right as
 * replacing its pending photos, so the registrant is now recorded.
 *
 * Existing rows are backfilled from their media.register event; rows without
 * one keep NULL and fall back to "the visit's assigned technician".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('registered_by_user_id')->nullable()->after('declared_source')->constrained('users')->nullOnDelete();
            $table->foreignId('registered_device_id')->nullable()->after('registered_by_user_id')->constrained('devices')->nullOnDelete();
        });

        DB::table('visit_events')
            ->where('event_type', 'media.register')
            ->whereNotNull('actor_user_id')
            ->orderBy('id')
            ->select(['id', 'payload', 'actor_user_id', 'device_id'])
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $clientMediaId = json_decode((string) $event->payload, true)['client_media_id'] ?? null;
                    if (! is_string($clientMediaId) || $clientMediaId === '') {
                        continue;
                    }

                    DB::table('media_files')
                        ->where('client_media_id', $clientMediaId)
                        ->whereNull('registered_by_user_id')
                        ->update([
                            'registered_by_user_id' => $event->actor_user_id,
                            'registered_device_id' => $event->device_id,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_device_id');
            $table->dropConstrainedForeignId('registered_by_user_id');
        });
    }
};

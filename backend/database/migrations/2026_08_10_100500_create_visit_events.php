<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The raw event log. Two jobs:
 *  1. Idempotent offline sync — client_event_id is unique, so replaying a batch
 *     five times still produces one row.
 *  2. Fuel for batch 3 prediction, which needs ~500 real visits before it can be
 *     trained at all. Collect from day one or that phase can never start.
 *
 * Device clock is recorded but is NOT treated as truth on its own (adversarial
 * review, critical finding): a technician can change the phone clock. We keep the
 * device timestamp, a monotonic offset, the last trusted server time the device
 * saw, and the server receive time — then flag divergence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_event_id')->unique();       // idempotency key from the device
            $table->string('event_type', 48);
            $table->json('payload')->nullable();

            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('device_timestamp')->nullable();
            $table->bigInteger('monotonic_offset_ms')->nullable();
            $table->timestamp('last_trusted_server_time')->nullable();
            $table->timestamp('server_received_at');
            $table->integer('clock_divergence_seconds')->nullable();
            $table->boolean('clock_suspect')->default(false);

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('source', 24)->nullable();        // gps|qr|manual
            $table->string('integrity_hash', 64)->nullable();
            $table->string('sync_status', 16)->default('synced');
            $table->unsignedInteger('sequence')->nullable(); // per-device causal ordering
            $table->timestamps();

            $table->index(['visit_id', 'device_timestamp']);
            $table->index(['device_id', 'sequence']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_events');
    }
};

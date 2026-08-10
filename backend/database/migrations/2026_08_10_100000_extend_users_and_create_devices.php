<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVP roles are deliberately three (PRD v1.2 §2): owner_supervisor, technician, admin.
 * Sales rep and warehouse keeper are backlog — the owner holds those duties at two vehicles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('technician')->after('email');
            $table->string('phone', 32)->nullable()->after('role');
            $table->string('trade', 64)->nullable()->after('phone');       // profession on the work permit
            $table->json('specialties')->nullable()->after('trade');        // dispatch filtering
            $table->time('shift_start')->nullable()->after('specialties');
            $table->time('shift_end')->nullable()->after('shift_start');
            $table->boolean('is_active')->default(true)->after('shift_end');
            $table->index(['role', 'is_active']);
        });

        // Per-device tokens so a lost technician phone can be revoked without
        // locking the person out of a replacement handset.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid')->unique();
            $table->string('platform', 32)->default('android');
            $table->string('label', 120)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 190)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role', 'phone', 'trade', 'specialties',
                'shift_start', 'shift_end', 'is_active',
            ]);
        });
    }
};

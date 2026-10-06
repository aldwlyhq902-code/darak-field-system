<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A technician's SECOND handset now waits for the owner. The first device still
 * self-registers so onboarding stays a single login, but a stolen password can
 * no longer silently mint a 30-day token on an attacker's phone.
 *
 * Every device that exists today has been in use, so it is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('approval_status', 16)->default('approved')->after('revoked_reason');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            // Self-reported by the app (root / emulator). Shown to the owner,
            // never used to block: a determined attacker simply lies.
            $table->json('integrity')->nullable()->after('approved_by');
            $table->index(['user_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'approval_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'integrity']);
        });
    }
};

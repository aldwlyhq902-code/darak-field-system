<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tombstones for API tokens deleted by a revocation.
 *
 * Revoking a device or disabling an account deletes its tokens, so the phone's
 * next request used to fail authentication with a plain 401 — which the app
 * reads as "session expired, keep the work" — and the remote wipe never ran.
 * The SHA-256 of each deleted token is kept here until the token would have
 * expired anyway, so the failed request can answer 403 DEVICE_REVOKED or
 * USER_INACTIVE instead. The token itself stays dead: nothing here authenticates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revoked_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('code', 32); // DEVICE_REVOKED | USER_INACTIVE
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE revoked_tokens ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_tokens');
    }
};

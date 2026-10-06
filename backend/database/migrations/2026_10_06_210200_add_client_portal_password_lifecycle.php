<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client-portal accounts get the same lifecycle as panel accounts: a reset
 * broker of their own (separate token table, separate provider) and an
 * auth_version that ends every other session when the password changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_portal_users', function (Blueprint $table) {
            $table->unsignedInteger('auth_version')->default(0)->after('password');
        });

        Schema::create('client_portal_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Same defense in depth as every other public table.
            DB::statement('ALTER TABLE client_portal_password_reset_tokens ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_password_reset_tokens');
        Schema::table('client_portal_users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};

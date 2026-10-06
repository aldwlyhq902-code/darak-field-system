<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit chain v2.
 *
 * hash_version records which digest sealed each row: 1 = plain SHA-256 (every
 * row written so far), 2 = HMAC-SHA256 under DARAK_AUDIT_HMAC_KEY. Without the
 * key, anyone with write access could recompute a plain SHA-256 chain after
 * editing history; with it, they cannot.
 *
 * The row trigger already refuses UPDATE and DELETE, but TRUNCATE is a separate
 * statement-level event that row triggers never see. One TRUNCATE emptied the
 * whole evidential log without tripping anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A constant default is a catalogue-only change on PostgreSQL 11+: no row
        // is rewritten and no UPDATE trigger fires, so immutability holds.
        if (! Schema::hasColumn('audit_logs', 'hash_version')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->unsignedSmallInteger('hash_version')->default(1);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
                CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION darak_prevent_audit_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;');
        }

        // hash_version stays: HMAC rows cannot be verified without it, and a
        // rollback must never erase evidentiary data.
    }
};

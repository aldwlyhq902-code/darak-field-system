<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `external_documents.idempotency_key` holds SEMANTIC keys, not uuids.
 *
 * The column was declared `uuid` while the code wrote "invoice:visit:39" and
 * "cn:<uuid>". PostgreSQL rejects both, so no invoice and no credit note could
 * ever be issued in production — and the whole suite stayed green because SQLite
 * accepts any string in a column declared uuid.
 *
 * The key is widened rather than hashed on purpose: "invoice:visit:39" tells an
 * operator which visit a document belongs to at a glance, and a uuid does not.
 * Uniqueness, which is what actually enforces replay-safety, is unaffected.
 *
 * `visit_events.client_event_id` and `stock_moves.idempotency_key` stay `uuid` —
 * those really are device-generated uuids, and derived events now use UUIDv5.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return; // SQLite stores it as TEXT either way
        }

        Schema::table('external_documents', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
        });

        // Postgres will not implicitly cast uuid -> varchar.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE external_documents ALTER COLUMN idempotency_key TYPE VARCHAR(191) USING idempotency_key::text'
        );

        Schema::table('external_documents', function (Blueprint $table) {
            $table->unique('idempotency_key');
        });

        // SUB-TMP-<uuid> is 44 characters and overflowed varchar(32) on insert.
        // The placeholder is now short, but the column has no reason to be tight.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE subcontractor_orders ALTER COLUMN order_no TYPE VARCHAR(64)'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE subcontractor_orders ALTER COLUMN order_no TYPE VARCHAR(32)'
        );

        Schema::table('external_documents', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
        });

        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE external_documents ALTER COLUMN idempotency_key TYPE UUID USING idempotency_key::uuid'
        );

        Schema::table('external_documents', function (Blueprint $table) {
            $table->unique('idempotency_key');
        });
    }
};

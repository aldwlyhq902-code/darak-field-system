<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence must outlive the client record.
 *
 * Before this, one `DELETE FROM clients WHERE id = 7` — or a stray forceDelete in
 * tinker — cascaded down through sites, work orders and visits and destroyed
 * visit_events (the sync and clock-tamper log), media_files (the sha256 integrity
 * proof for every photo and signature) and checklist_instances. Meanwhile the
 * issued invoices survived in external_documents with client_id nulled: money
 * records with zero supporting evidence, which is exactly the state a billing
 * dispute or an audit would find indefensible.
 *
 * Clients already use SoftDeletes; this makes the hard path refuse rather than
 * quietly succeed. Restricting at the top of the chain (sites -> clients) is
 * enough: a client with any site can no longer be hard-deleted, so nothing
 * beneath it can be reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot alter a foreign key in place; the guard on the model
        // covers development, and production is PostgreSQL.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->foreign('site_id')->references('id')->on('sites')->restrictOnDelete();
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['work_order_id']);
            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
        });

        Schema::table('visit_events', function (Blueprint $table) {
            $table->dropForeign(['visit_id']);
            $table->foreign('visit_id')->references('id')->on('visits')->restrictOnDelete();
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropForeign(['visit_id']);
            $table->foreign('visit_id')->references('id')->on('visits')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ([
            ['media_files', 'visit_id', 'visits'],
            ['visit_events', 'visit_id', 'visits'],
            ['visits', 'work_order_id', 'work_orders'],
            ['assets', 'site_id', 'sites'],
            ['sites', 'client_id', 'clients'],
        ] as [$table, $column, $references]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($references)->cascadeOnDelete();
            });
        }
    }
};

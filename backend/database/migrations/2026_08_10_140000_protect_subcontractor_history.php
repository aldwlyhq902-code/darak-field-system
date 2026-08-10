<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontractor cost records must survive the partner.
 *
 * `visits.subcontractor_id` was declared with no foreign key at all, and
 * subcontractor_orders cascaded on delete — so removing a partner erased the
 * purchase costs of every job they ever did. Those costs are the difference
 * between a real margin and a fictional one on work already invoiced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontractors', function (Blueprint $table) {
            $table->softDeletes();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return; // SQLite cannot alter FKs in place; production is PostgreSQL
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->nullOnDelete();
        });

        Schema::table('subcontractor_orders', function (Blueprint $table) {
            $table->dropForeign(['subcontractor_id']);
            $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subcontractors', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['subcontractor_id']);
        });

        Schema::table('subcontractor_orders', function (Blueprint $table) {
            $table->dropForeign(['subcontractor_id']);
            $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->cascadeOnDelete();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Working-hours policy is supplied by a qualified specialist, never hard-coded
 * (PRD v1.2 §3.4). Each company keeps immutable, versioned policies; every rule
 * is optional and an absent rule simply is not checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operating_company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->text('source');
            $table->text('approved_by');
            $table->date('approved_at');
            $table->json('rules');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['operating_company_id', 'version']);
            $table->index(['operating_company_id', 'effective_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_policies ENABLE ROW LEVEL SECURITY');
            // Versions are immutable at the database too, not only in the model.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION darak_prevent_work_policy_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'work_policies versions are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER work_policies_immutable
                BEFORE UPDATE OR DELETE ON work_policies
                FOR EACH ROW EXECUTE FUNCTION darak_prevent_work_policy_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS work_policies_immutable ON work_policies; DROP FUNCTION IF EXISTS darak_prevent_work_policy_mutation();');
        }
        Schema::dropIfExists('work_policies');
    }
};

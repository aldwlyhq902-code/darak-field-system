<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_number', 32)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->default('reactive'); // preventive|reactive|out_of_contract
            $table->string('priority', 16)->default('normal'); // low|normal|urgent
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('sla_due_at')->nullable();       // computed inside the service window
            $table->unsignedInteger('sla_minutes_budget')->nullable();
            $table->string('status', 24)->default('open');     // open|scheduled|in_progress|closed|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'status']);
            $table->index('sla_due_at');
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subcontractor_id')->nullable();

            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();

            // MVP state machine (PRD v1.2 §2). Any transition outside the allowed
            // map returns HTTP 409 INVALID_VISIT_TRANSITION.
            $table->string('state', 24)->default('scheduled');
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('on_site_seconds')->default(0);

            // Rework: linked to the original visit, not billed by default, counts
            // against first-time-fix. Classification is proposed by the system and
            // only a supervisor may override it.
            $table->foreignId('parent_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->boolean('is_rework')->default(false);
            $table->string('rework_reason', 64)->nullable(); // wrong_part|incomplete_diagnosis|fault_returned|client_request
            $table->boolean('rework_system_flagged')->default(false);
            $table->foreignId('rework_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rework_override_note')->nullable();

            $table->boolean('is_billable')->default(true);
            $table->json('close_blockers')->nullable();      // last computed missing-items list
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_user_id', 'state']);
            $table->index(['site_id', 'scheduled_start']);
            $table->index('parent_visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
        Schema::dropIfExists('work_orders');
    }
};

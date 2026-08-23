<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('frequency_days');
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->time('preferred_start')->nullable();
            $table->date('next_due_on');
            $table->date('last_generated_on')->nullable();
            $table->foreignId('preferred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_due_on']);
        });

        Schema::create('client_service_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 24);
            $table->text('description');
            $table->date('preferred_date');
            $table->string('preferred_time_slot', 16);
            $table->string('status', 16)->default('pending');
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->text('response_note')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'preferred_date']);
        });

        Schema::create('visit_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('client_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->boolean('resolution_confirmed')->default(true);
            $table->text('comment')->nullable();
            $table->boolean('is_complaint')->default(false);
            $table->string('status', 16)->default('new');
            $table->text('supervisor_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['is_complaint', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_feedback');
        Schema::dropIfExists('client_service_requests');
        Schema::dropIfExists('maintenance_plans');
    }
};

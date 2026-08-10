<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('asset_type', 32);
            $table->string('name');
            $table->json('items');            // [{key, label_ar, label_en, requires_photo}]
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['asset_type', 'is_active']);
        });

        Schema::create('checklist_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_generated_uuid')->nullable()->unique();
            // A visit cannot close while any scheduled asset lacks a status.
            $table->string('status', 24)->nullable(); // ok|needs_followup|fault
            $table->json('items')->nullable();
            $table->text('note')->nullable();
            $table->boolean('no_parts_used')->default(false); // explicit "no parts" satisfies the close gate
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['visit_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_instances');
        Schema::dropIfExists('checklist_templates');
    }
};

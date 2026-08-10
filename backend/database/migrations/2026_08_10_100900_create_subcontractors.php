<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hood cleaning, gas work and pipe extensions all run through subcontractors, and
 * the entire phase-1 field test is 100% subcontracted. Without this entity a real
 * cost disappears from every margin calculation.
 *
 * MVP scope is deliberately Vendor + Cost + Attachment. Scoring, comparison and
 * smart capacity coverage are backlog.
 *
 * Subcontractors get NO app login in MVP — the supervisor uploads their documents.
 * Granting an external party the technician app without a scoped role would expose
 * other clients' data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcontractors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cr_number', 32)->nullable();
            $table->json('specialties')->nullable(); // hood|gas|electrical|plumbing|hvac
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('issues_official_certificates')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('subcontractor_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontractor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_no', 32)->unique();
            $table->decimal('purchase_cost', 12, 2);   // what we pay the partner
            $table->decimal('sale_price', 12, 2);      // what the client pays — margin shown before confirming
            $table->string('status', 24)->default('assigned'); // assigned|in_progress|delivered|cancelled
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('documents')->nullable();     // stored under the ISSUING party's name
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['subcontractor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontractor_orders');
        Schema::dropIfExists('subcontractors');
    }
};

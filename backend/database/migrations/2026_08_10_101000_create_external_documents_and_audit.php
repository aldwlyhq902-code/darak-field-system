<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices are NOT built here. They are issued by the external e-invoicing provider
 * and we keep a reference plus status only.
 *
 * Critical correction from the adversarial review: an issued e-invoice cannot be
 * edited or deleted. A part return therefore creates a linked CREDIT NOTE request —
 * it never mutates the original document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 24);        // invoice|credit_note|debit_note
            $table->string('provider', 48)->default('fake');
            $table->string('external_id', 96)->nullable();
            $table->string('external_number', 64)->nullable();
            $table->foreignId('parent_document_id')->nullable()->constrained('external_documents')->nullOnDelete();

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 12, 2)->default(0);     // pre-VAT
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->string('status', 24)->default('requested'); // requested|issued|failed|cancelled
            $table->timestamp('issued_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['doc_type', 'status']);
            $table->index(['client_id', 'issued_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('external_documents');
    }
};

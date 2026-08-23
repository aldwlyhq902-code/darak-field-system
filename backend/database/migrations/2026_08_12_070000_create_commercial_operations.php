<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('series_uuid');
            $table->unsignedInteger('version')->default(1);
            $table->string('quote_no', 48)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('package_code', 32);
            $table->decimal('price_amount', 12, 2); // recurring cycle amount, pre-VAT
            $table->decimal('vat_rate', 5, 4)->default(0.15);
            $table->string('billing_cycle', 16)->default('monthly');
            $table->unsignedInteger('duration_months')->default(12);
            $table->date('starts_on');
            $table->date('valid_until');
            $table->time('service_window_start')->default('07:00:00');
            $table->time('service_window_end')->default('23:00:00');
            $table->unsignedInteger('sla_minutes')->default(240);
            $table->json('terms')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_portal_user_id')->nullable()->constrained('client_portal_users')->nullOnDelete();
            $table->foreignId('converted_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['series_uuid', 'version']);
            $table->index(['client_id', 'status', 'valid_until']);
        });

        Schema::create('quotation_site', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['quotation_id', 'site_id']);
        });

        Schema::create('contract_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('installment_no');
            $table->date('due_on');
            $table->decimal('amount', 12, 2); // pre-VAT
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'installment_no']);
            $table->index(['status', 'due_on']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 48)->unique();
            $table->foreignId('contract_installment_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 24);
            $table->string('reference', 96)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('contract_installments');
        Schema::dropIfExists('quotation_site');
        Schema::dropIfExists('quotations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_work_approvals', function (Blueprint $table): void {
            $table->json('items')->nullable()->after('description');
            $table->string('requested_from', 24)->default('panel')->after('created_by');
        });

        Schema::create('request_for_quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('rfq_no', 48)->unique();
            $table->foreignId('destination_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->date('response_due_on');
            $table->string('status', 24)->default('draft');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('awarded_supplier_quotation_id')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'response_due_on']);
        });
        Schema::create('request_for_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->text('specification')->nullable();
            $table->timestamps();
            $table->unique(['request_for_quotation_id', 'part_id']);
        });
        Schema::create('supplier_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_reference', 96)->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('status', 24)->default('received');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['request_for_quotation_id', 'supplier_id']);
        });
        Schema::create('supplier_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();
            $table->unique(['supplier_quotation_id', 'part_id']);
        });
        Schema::table('request_for_quotations', function (Blueprint $table): void {
            $table->foreign('awarded_supplier_quotation_id', 'rfq_awarded_quote_fk')->references('id')->on('supplier_quotations')->nullOnDelete();
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->decimal('technician_lat', 10, 7)->nullable();
            $table->decimal('technician_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->string('route_provider', 32)->nullable();
            $table->decimal('route_distance_km', 10, 2)->nullable();
        });

        Schema::create('fault_prediction_models', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type', 48)->default('logistic_recurrence_90d');
            $table->json('coefficients');
            $table->json('feature_scaling');
            $table->unsignedInteger('training_samples');
            $table->unsignedInteger('validation_samples');
            $table->decimal('accuracy', 6, 4)->nullable();
            $table->decimal('precision', 6, 4)->nullable();
            $table->decimal('recall', 6, 4)->nullable();
            $table->timestamp('trained_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('asset_fault_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fault_prediction_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('risk_score', 6, 4);
            $table->json('feature_snapshot');
            $table->date('horizon_ends_on');
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['fault_prediction_model_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_fault_predictions');
        Schema::dropIfExists('fault_prediction_models');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn(['technician_lat', 'technician_lng', 'location_updated_at', 'route_provider', 'route_distance_km']));
        Schema::table('request_for_quotations', fn (Blueprint $table) => $table->dropForeign('rfq_awarded_quote_fk'));
        Schema::dropIfExists('supplier_quotation_items');
        Schema::dropIfExists('supplier_quotations');
        Schema::dropIfExists('request_for_quotation_items');
        Schema::dropIfExists('request_for_quotations');
        Schema::table('additional_work_approvals', fn (Blueprint $table) => $table->dropColumn(['items', 'requested_from']));
    }
};

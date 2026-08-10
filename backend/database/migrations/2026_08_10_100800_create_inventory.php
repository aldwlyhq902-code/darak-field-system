<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVP inventory is deliberately five movement types (PRD v1.2 §2). Transfer between
 * vehicles, reservation and advanced stocktake are backlog — they need offline
 * conflict resolution that is not worth the risk before real operating data exists.
 *
 * Quantities are DECIMAL, never float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate', 32)->unique();
            $table->string('internal_code', 32)->nullable();
            $table->string('model', 96)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);   // warehouse|vehicle
            $table->string('name');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('type');
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 48)->unique();
            $table->string('name');
            $table->string('unit', 16)->default('pcs');
            $table->decimal('purchase_cost', 12, 2);   // required: margin cannot be computed without it
            $table->decimal('sale_price', 12, 2);      // pre-VAT
            $table->decimal('member_price', 12, 2)->nullable(); // contract-holder price
            $table->string('qr_code', 64)->nullable()->unique();
            // Heat sensitivity is recorded per SKU from the manufacturer sheet.
            // There is deliberately NO global 54C threshold — that claim was unproven
            // and some capacitors are rated to 105C.
            $table->boolean('heat_sensitive')->default(false);
            $table->decimal('max_storage_temp_c', 5, 2)->nullable();
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('stock_moves', function (Blueprint $table) {
            $table->id();
            $table->string('move_type', 24); // RECEIPT|VEHICLE_LOAD|VISIT_ISSUE|VISIT_RETURN|ADJUSTMENT
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 12, 3);
            $table->foreignId('from_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('idempotency_key')->unique();  // replay-safe offline issue
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_moves')->nullOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable(); // snapshot at movement time
            $table->timestamp('device_timestamp')->nullable();
            $table->timestamp('server_received_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['part_id', 'to_location_id']);
            $table->index(['part_id', 'from_location_id']);
            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('vehicles');
    }
};

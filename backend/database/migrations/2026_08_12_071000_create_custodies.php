<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custodies', function (Blueprint $table): void {
            $table->id();
            $table->string('custody_no', 48)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type', 24); // vehicle|device|tool|stock
            $table->string('item_name');
            $table->string('serial_number', 96)->nullable();
            $table->text('condition_out')->nullable();
            $table->text('condition_in')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('status', 24)->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custodies');
    }
};

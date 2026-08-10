<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // split_ac|package_ac|chiller|freezer|hood|exhaust_fan|gas|electrical|plumbing
            $table->string('name');
            $table->string('manufacturer', 96)->nullable();
            $table->string('model', 96)->nullable();
            $table->string('serial_number', 96)->nullable();
            $table->string('location_in_site', 190)->nullable();
            $table->date('installed_on')->nullable();
            // Warranty is a blocking warning before billing a part, not decoration.
            $table->string('warranty_provider', 120)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status', 32)->default('operational'); // operational|needs_followup|faulty|retired
            $table->string('qr_code', 64)->nullable()->unique();
            $table->uuid('client_generated_uuid')->nullable()->unique(); // dedupe assets created offline
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['site_id', 'type']);
            $table->index('warranty_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

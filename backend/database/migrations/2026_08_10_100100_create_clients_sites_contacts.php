<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clients -> sites -> assets exists from day one (PRD v1.2 §2) even though the
 * MVP UI shows a single site per client. Retro-fitting a site dimension into
 * contracts, visits and invoices later is the expensive path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('commercial_name')->nullable();
            $table->string('cr_number', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('category', 32)->default('restaurant'); // restaurant|cafe|central_kitchen|chain
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->string('payment_term', 32)->default('quarterly_advance'); // monthly_card|quarterly_advance
            $table->date('established_on')->nullable(); // drives the "<1 year = advance payment" rule
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active');
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->default(100);
            $table->unsignedInteger('dwell_threshold_s')->default(120);
            $table->text('access_notes')->nullable();   // back gate, mall permit, etc.
            $table->json('no_visit_windows')->nullable(); // peak hours the client refuses visits
            $table->string('qr_code', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'is_active']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('position', 64)->nullable();
            $table->boolean('can_approve')->default(false); // may sign off quotes / close-out
            $table->timestamps();
            $table->index(['client_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('clients');
    }
};

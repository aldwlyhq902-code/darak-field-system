<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money rule (PRD v1.1 §1, carried into v1.2): every amount is stored PRE-VAT with an
 * explicit vat_rate. Ambiguity here previously produced a real 73.5% vs 85% error.
 *
 * service_window_* is not decoration: the SLA clock only runs inside it. Without that
 * rule an evening ticket shows red every morning and the dashboard lies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('contract_no', 32)->unique();
            $table->string('package_code', 32);              // basic|comprehensive
            $table->decimal('price_amount', 12, 2);          // PRE-VAT
            $table->decimal('vat_rate', 5, 4)->default(0.15);
            $table->string('billing_cycle', 16)->default('monthly'); // monthly|quarterly
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            // Service window — SLA counts only inside it.
            $table->time('service_window_start')->default('07:00:00');
            $table->time('service_window_end')->default('23:00:00');
            $table->unsignedInteger('sla_minutes')->default(240);

            $table->unsignedInteger('included_assets_cap')->default(8);
            $table->decimal('extra_asset_price', 10, 2)->default(100); // per asset beyond the cap
            $table->unsignedInteger('included_visits_per_cycle')->default(1);
            $table->decimal('included_hours_per_cycle', 8, 2)->nullable();
            $table->json('exclusions')->nullable();          // pre-existing faults, after 23:00, goods, hot kitchen equipment
            $table->decimal('founding_inspection_fee', 10, 2)->default(350);
            $table->boolean('founding_inspection_credited')->default(false);

            $table->boolean('is_trial')->default(false);     // 90-day trial contract
            $table->date('decision_due_on')->nullable();     // feeds the 4-of-5 renewal gate
            $table->string('status', 24)->default('draft');  // draft|active|suspended|ended|cancelled
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'status']);
            $table->index('decision_due_on');
        });

        Schema::create('contract_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_site');
        Schema::dropIfExists('contracts');
    }
};

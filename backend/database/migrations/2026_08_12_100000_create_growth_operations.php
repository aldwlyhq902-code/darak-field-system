<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('cr_number', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('operating_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operating_company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['operating_company_id', 'code']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('hourly_cost', 10, 2)->default(0);
            $table->boolean('is_emergency_backup')->default(false);
            $table->json('permissions')->nullable();
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operational_status', 24)->default('available');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->decimal('down_payment_amount', 12, 2)->default(0);
            $table->json('custom_installments')->nullable();
            $table->foreignId('renews_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->boolean('auto_generated')->default(false);
            $table->timestamp('superseded_at')->nullable();
            $table->string('acceptance_ip_hash', 64)->nullable();
        });
        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('source_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('signed_by_portal_user_id')->nullable()->constrained('client_portal_users')->nullOnDelete();
            $table->string('signed_name')->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->boolean('auto_renewal_offer')->default(true);
        });
        Schema::table('client_portal_users', function (Blueprint $table): void {
            $table->json('permissions')->nullable();
        });
        Schema::create('client_portal_user_site', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_portal_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unique(['client_portal_user_id', 'site_id']);
        });

        Schema::create('operational_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('incurred_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['contract_id', 'incurred_on']);
            $table->index(['visit_id', 'category']);
        });

        Schema::table('parts', function (Blueprint $table): void {
            $table->boolean('critical_tracking')->default(false);
            $table->unsignedSmallInteger('default_warranty_months')->nullable();
        });
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 96)->nullable();
            $table->string('serial_number', 96)->nullable();
            $table->date('manufactured_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->decimal('qty_received', 12, 3);
            $table->decimal('qty_remaining', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();
            $table->unique(['part_id', 'serial_number']);
            $table->index(['part_id', 'lot_number']);
        });
        Schema::table('stock_moves', function (Blueprint $table): void {
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('replenishment_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_no', 48)->unique();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('suggested_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('current_qty', 12, 3);
            $table->decimal('target_qty', 12, 3);
            $table->decimal('suggested_qty', 12, 3);
            $table->string('status', 24)->default('open');
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['part_id', 'stock_location_id', 'status']);
        });
        Schema::create('stocktake_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('open');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('stocktake_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_qty', 12, 3);
            $table->decimal('counted_qty', 12, 3);
            $table->decimal('variance_qty', 12, 3);
            $table->string('scan_code', 96)->nullable();
            $table->timestamps();
            $table->unique(['stocktake_session_id', 'part_id']);
        });
        Schema::create('part_failure_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_kind', 48);
            $table->text('note')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'created_at']);
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->decimal('replacement_value', 12, 2)->nullable();
            $table->string('criticality', 16)->default('normal');
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('fault_code', 64)->nullable();
            $table->string('diagnosis_code', 64)->nullable();
            $table->text('resolution_summary')->nullable();
        });
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('fault_code', 64)->nullable();
            $table->string('asset_type', 32)->nullable();
            $table->string('title');
            $table->text('symptoms')->nullable();
            $table->text('diagnosis');
            $table->text('solution');
            $table->json('suggested_part_ids')->nullable();
            $table->json('image_paths')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_type', 'fault_code']);
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedInteger('travel_seconds')->default(0);
            $table->unsignedInteger('waiting_seconds')->default(0);
            $table->timestamp('estimated_arrival_at')->nullable();
            $table->unsignedSmallInteger('route_sequence')->nullable();
        });
        Schema::create('technician_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 64);
            $table->string('status', 16)->default('approved');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_on', 'ends_on']);
        });
        Schema::create('vehicle_outages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('reason');
            $table->string('status', 16)->default('open');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('report_disputes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->text('description');
            $table->string('attachment_path')->nullable();
            $table->string('status', 16)->default('new');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('lead_no', 48)->unique();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('stage', 24)->default('new');
            $table->decimal('estimated_value', 12, 2)->default(0);
            $table->date('next_action_on')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['stage', 'next_action_on']);
        });
        Schema::create('sales_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->text('note');
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('vehicle_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('category', 24);
            $table->decimal('amount', 12, 2);
            $table->date('incurred_on');
            $table->decimal('odometer_km', 10, 1)->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('applies_to_role', 32);
            $table->string('basis', 24);
            $table->decimal('rate', 8, 4);
            $table->decimal('fixed_amount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('basis_amount', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });
        Schema::create('financial_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->string('action_type', 32);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending_first');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('first_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->timestamp('second_approved_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('custodies', function (Blueprint $table): void {
            $table->string('accepted_signature_hash', 64)->nullable();
            $table->string('accepted_name')->nullable();
            $table->decimal('loss_amount', 12, 2)->default(0);
            $table->text('loss_note')->nullable();
        });
        Schema::table('vehicle_stock_transfers', function (Blueprint $table): void {
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('previous_hash', 64)->nullable();
            $table->string('entry_hash', 64)->nullable()->unique();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION darak_prevent_audit_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION darak_prevent_audit_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs; DROP FUNCTION IF EXISTS darak_prevent_audit_mutation();');
        }
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn(['previous_hash', 'entry_hash']));
        Schema::table('vehicle_stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn('released_at');
        });
        Schema::table('custodies', fn (Blueprint $table) => $table->dropColumn(['accepted_signature_hash', 'accepted_name', 'loss_amount', 'loss_note']));
        Schema::dropIfExists('financial_approvals');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('vehicle_expenses');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_leads');
        Schema::dropIfExists('report_disputes');
        Schema::dropIfExists('vehicle_outages');
        Schema::dropIfExists('technician_absences');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn(['travel_seconds', 'waiting_seconds', 'estimated_arrival_at', 'route_sequence']));
        Schema::dropIfExists('knowledge_articles');
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn(['fault_code', 'diagnosis_code', 'resolution_summary']));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['replacement_value', 'criticality']));
        Schema::dropIfExists('part_failure_reports');
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktake_sessions');
        Schema::dropIfExists('replenishment_requests');
        Schema::table('stock_moves', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_lot_id'));
        Schema::dropIfExists('inventory_lots');
        Schema::table('parts', fn (Blueprint $table) => $table->dropColumn(['critical_tracking', 'default_warranty_months']));
        Schema::dropIfExists('operational_costs');
        Schema::dropIfExists('client_portal_user_site');
        Schema::table('client_portal_users', fn (Blueprint $table) => $table->dropColumn('permissions'));
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_quotation_id');
            $table->dropConstrainedForeignId('signed_by_portal_user_id');
            $table->dropColumn(['signed_name', 'signature_hash', 'signed_at', 'auto_renewal_offer']);
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('renews_contract_id');
            $table->dropColumn(['down_payment_amount', 'custom_installments', 'auto_generated', 'superseded_at', 'acceptance_ip_hash']);
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operating_branch_id');
            $table->dropColumn('operational_status');
        });
        Schema::table('stock_locations', fn (Blueprint $table) => $table->dropConstrainedForeignId('operating_branch_id'));
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operating_company_id');
            $table->dropConstrainedForeignId('operating_branch_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operating_branch_id');
            $table->dropColumn(['hourly_cost', 'is_emergency_backup', 'permissions']);
        });
        Schema::dropIfExists('operating_branches');
        Schema::dropIfExists('operating_companies');
    }
};

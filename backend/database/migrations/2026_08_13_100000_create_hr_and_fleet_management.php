<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_no', 48)->nullable()->unique();
            $table->string('nationality', 100)->nullable();
            $table->date('hired_on')->nullable();
            $table->decimal('annual_leave_days', 5, 2)->default(21);
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('document_number', 96)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('issuer')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['operating_branch_id', 'expires_on']);
            $table->index(['user_id', 'type', 'status']);
        });

        Schema::create('employee_leaves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 6, 2);
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->text('response_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['operating_branch_id', 'status', 'starts_on']);
            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        Schema::table('technician_absences', function (Blueprint $table): void {
            $table->foreignId('employee_leave_id')->nullable()->unique()->constrained('employee_leaves')->nullOnDelete();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('make', 96)->nullable();
            $table->string('vin', 64)->nullable()->unique();
            $table->decimal('current_odometer_km', 12, 1)->default(0);
            $table->date('last_service_on')->nullable();
            $table->date('next_service_on')->nullable();
            $table->decimal('next_service_odometer_km', 12, 1)->nullable();
        });

        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('document_number', 96)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('provider')->nullable();
            $table->string('coverage_type', 96)->nullable();
            $table->decimal('insured_value', 12, 2)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['operating_branch_id', 'expires_on']);
            $table->index(['vehicle_id', 'type', 'status']);
        });

        Schema::create('vehicle_maintenance_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no', 48)->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('priority', 16)->default('normal');
            $table->string('status', 20)->default('open');
            $table->text('description');
            $table->string('vendor_name')->nullable();
            $table->string('quote_reference')->nullable();
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->decimal('actual_cost', 12, 2)->default(0);
            $table->date('opened_on');
            $table->date('scheduled_for')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->date('next_service_on')->nullable();
            $table->decimal('next_service_odometer_km', 12, 1)->nullable();
            $table->boolean('causes_outage')->default(true);
            $table->foreignId('vehicle_outage_id')->nullable()->constrained('vehicle_outages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['operating_branch_id', 'status', 'scheduled_for']);
            $table->index(['vehicle_id', 'opened_on']);
        });

        Schema::create('vehicle_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('inspection_no', 48)->unique();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operating_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at');
            $table->decimal('odometer_km', 12, 1);
            $table->json('checklist');
            $table->boolean('is_roadworthy')->default(true);
            $table->text('defects')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_name')->nullable();
            $table->string('photo_mime_type', 96)->nullable();
            $table->unsignedBigInteger('photo_size')->nullable();
            $table->timestamps();
            $table->index(['operating_branch_id', 'inspected_at']);
            $table->index(['vehicle_id', 'inspected_at']);
        });

        Schema::table('vehicle_expenses', function (Blueprint $table): void {
            $table->foreignId('vehicle_maintenance_order_id')->nullable()->unique()->constrained('vehicle_maintenance_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('vehicle_maintenance_order_id'));
        Schema::dropIfExists('vehicle_inspections');
        Schema::dropIfExists('vehicle_maintenance_orders');
        Schema::dropIfExists('vehicle_documents');
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['make', 'vin', 'current_odometer_km', 'last_service_on', 'next_service_on', 'next_service_odometer_km']));
        Schema::table('technician_absences', fn (Blueprint $table) => $table->dropConstrainedForeignId('employee_leave_id'));
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_profiles');
    }
};

<?php

namespace Tests\Feature;

use App\Models\EmployeeDocument;
use App\Models\EmployeeLeave;
use App\Models\NotificationMessage;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleMaintenanceOrder;
use App\Models\VehicleOutage;
use App\Services\ComplianceAlertService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

class HrFleetManagementTest extends DarakTestCase
{
    public function test_hr_panel_saves_employee_profile_and_versions_private_documents(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner, 'web')->get(route('panel.hr'))
            ->assertOk()->assertSee('شؤون الموظفين والامتثال');

        $this->put(route('panel.hr.profile', $this->technician), [
            'employee_no' => 'EMP-100', 'nationality' => 'سعودي',
            'hired_on' => '2025-01-01', 'annual_leave_days' => 30,
            'emergency_contact_name' => 'جهة الطوارئ', 'emergency_contact_phone' => '0500000000',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->post(route('panel.hr.document', $this->technician), [
            'type' => 'iqama', 'document_number' => '2000000000',
            'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(30)->toDateString(),
            'file' => UploadedFile::fake()->create('iqama.pdf', 80, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('ok');
        $first = EmployeeDocument::firstOrFail();
        Storage::disk('local')->assertExists($first->file_path);

        $this->post(route('panel.hr.document', $this->technician), [
            'type' => 'iqama', 'document_number' => '2000000001',
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $this->technician->id, 'employee_no' => 'EMP-100']);
        $this->assertDatabaseHas('employee_documents', ['user_id' => $this->technician->id, 'document_number' => '2000000001', 'status' => 'active']);
    }

    public function test_approved_leave_creates_absence_and_reassigns_affected_work(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.hr.leave'), [
            'user_id' => $this->technician->id, 'type' => 'annual',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString(),
            'reason' => 'إجازة مخططة',
        ])->assertRedirect()->assertSessionHas('ok');

        $leave = EmployeeLeave::firstOrFail();
        $this->post(route('panel.hr.leave.decision', $leave), ['decision' => 'approve'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertDatabaseHas('technician_absences', [
            'employee_leave_id' => $leave->id, 'user_id' => $this->technician->id, 'status' => 'approved',
        ]);
        $this->assertNotSame($this->technician->id, $this->visit->fresh()->assigned_user_id);
    }

    public function test_fleet_workflow_tracks_documents_outage_repair_cost_and_next_service(): void
    {
        Storage::fake('local');
        $vehicle = Vehicle::where('assigned_user_id', $this->technician->id)->firstOrFail();
        $this->actingAs($this->owner, 'web')->get(route('panel.fleet'))
            ->assertOk()->assertSee('إدارة الأسطول');

        $this->post(route('panel.fleet.document', $vehicle), [
            'type' => 'insurance', 'document_number' => 'POL-100',
            'provider' => 'شركة التأمين', 'expires_on' => now()->addDays(20)->toDateString(),
            'file' => UploadedFile::fake()->create('policy.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('ok');

        $this->post(route('panel.fleet.maintenance', $vehicle), [
            'type' => 'repair', 'priority' => 'high', 'description' => 'إصلاح نظام التبريد',
            'estimated_cost' => 700, 'opened_on' => now()->toDateString(), 'causes_outage' => 1,
        ])->assertRedirect()->assertSessionHas('ok');
        $order = VehicleMaintenanceOrder::firstOrFail();

        $this->post(route('panel.fleet.maintenance.transition', $order), ['status' => 'approved'])->assertRedirect();
        $this->post(route('panel.fleet.maintenance.transition', $order), [
            'status' => 'in_progress', 'vendor_name' => 'ورشة الاختبار', 'estimated_cost' => 750,
        ])->assertRedirect();
        $this->assertSame('out_of_service', $vehicle->fresh()->operational_status);
        $this->assertDatabaseHas('vehicle_outages', ['vehicle_id' => $vehicle->id, 'status' => 'open']);

        $this->post(route('panel.fleet.maintenance.transition', $order), [
            'status' => 'completed', 'actual_cost' => 825.50, 'completed_on' => now()->toDateString(),
            'odometer_km' => 45000, 'next_service_on' => now()->addMonths(6)->toDateString(),
            'next_service_odometer_km' => 50000,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('available', $vehicle->fresh()->operational_status);
        $this->assertDatabaseHas('vehicle_expenses', ['vehicle_maintenance_order_id' => $order->id, 'amount' => 825.50]);
        $this->assertSame('closed', VehicleOutage::where('vehicle_id', $vehicle->id)->firstOrFail()->status);
        $this->assertSame('50000.0', $vehicle->fresh()->next_service_odometer_km);
        $this->assertNotNull(VehicleDocument::where('vehicle_id', $vehicle->id)->first()->file_path);
    }

    public function test_technician_can_inspect_only_the_assigned_vehicle_and_failure_stops_it(): void
    {
        $vehicle = Vehicle::where('assigned_user_id', $this->technician->id)->firstOrFail();
        $this->actingAs($this->technician, 'sanctum')->getJson('/api/v1/fleet/vehicle')
            ->assertOk()->assertJsonPath('data.id', $vehicle->id);

        $this->postJson('/api/v1/fleet/vehicle/inspection', [
            'odometer_km' => 12345, 'tires' => true, 'brakes' => false,
            'lights' => true, 'fluids' => true, 'body' => true, 'cleanliness' => true,
            'defects' => 'ملاحظة حرجة على الفرامل',
        ])->assertCreated()->assertJsonPath('data.is_roadworthy', false);

        $this->assertSame('out_of_service', $vehicle->fresh()->operational_status);
        $this->assertDatabaseHas('vehicle_inspections', ['vehicle_id' => $vehicle->id, 'is_roadworthy' => false]);
        $this->assertDatabaseHas('vehicle_outages', ['vehicle_id' => $vehicle->id, 'status' => 'open']);
    }

    public function test_compliance_alert_scan_is_idempotent_for_employee_vehicle_and_service_due_dates(): void
    {
        $vehicle = Vehicle::where('assigned_user_id', $this->technician->id)->firstOrFail();
        EmployeeDocument::create([
            'user_id' => $this->technician->id, 'type' => 'work_permit', 'status' => 'active',
            'expires_on' => now()->addDays(25),
        ]);
        VehicleDocument::create([
            'vehicle_id' => $vehicle->id, 'type' => 'registration', 'status' => 'active',
            'expires_on' => now()->addDays(6),
        ]);
        $vehicle->forceFill(['next_service_on' => now()->addDays(5), 'next_service_odometer_km' => 10000, 'current_odometer_km' => 9800])->save();

        $service = app(ComplianceAlertService::class);
        $this->assertSame(3, $service->scan());
        $this->assertSame(0, $service->scan());
        $this->assertDatabaseCount('notification_messages', 3);
        $this->assertSame(3, NotificationMessage::whereIn('type', [
            NotificationMessage::TYPE_EMPLOYEE_DOCUMENT_EXPIRING,
            NotificationMessage::TYPE_VEHICLE_DOCUMENT_EXPIRING,
            NotificationMessage::TYPE_VEHICLE_MAINTENANCE_DUE,
        ])->count());
    }

    public function test_compliance_notifications_are_isolated_between_branches(): void
    {
        $company = OperatingCompany::create(['name' => 'شركة الفروع', 'currency' => 'SAR', 'is_active' => true]);
        $branchA = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'فرع أ', 'code' => 'HR-A', 'is_active' => true]);
        $branchB = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'فرع ب', 'code' => 'HR-B', 'is_active' => true]);
        $admin = User::create([
            'name' => 'مسؤول الفرع', 'email' => 'branch-hr@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'operating_branch_id' => $branchA->id,
            'permissions' => ['operations', 'hr'], 'is_active' => true,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $own = NotificationMessage::create([
            'type' => NotificationMessage::TYPE_EMPLOYEE_DOCUMENT_EXPIRING, 'channel' => 'in_app',
            'recipient_kind' => 'supervisor', 'body' => 'تنبيه خاص بفرع أ',
            'context' => ['operating_branch_id' => $branchA->id], 'status' => 'queued',
            'attempts' => 0, 'idempotency_key' => 'branch-a-alert',
        ]);
        $other = NotificationMessage::create([
            'type' => NotificationMessage::TYPE_EMPLOYEE_DOCUMENT_EXPIRING, 'channel' => 'in_app',
            'recipient_kind' => 'supervisor', 'body' => 'تنبيه سري لفرع ب',
            'context' => ['operating_branch_id' => $branchB->id], 'status' => 'queued',
            'attempts' => 0, 'idempotency_key' => 'branch-b-alert',
        ]);

        $this->actingAs($admin, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertSee($own->body)->assertDontSee($other->body);
        $this->post(route('panel.notifications.sent', $other))->assertForbidden();
    }
}

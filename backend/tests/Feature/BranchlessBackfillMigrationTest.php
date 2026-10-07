<?php

namespace Tests\Feature;

use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/**
 * The data migration that repairs rows written with no branch before
 * BranchAssignment existed: the parent's branch first, otherwise the derived
 * company's only active branch; anything ambiguous is left for a person.
 */
class BranchlessBackfillMigrationTest extends DarakTestCase
{
    public function test_unambiguous_rows_get_a_branch_and_ambiguous_ones_are_left_alone(): void
    {
        // A second company with two active branches: its rows are ambiguous.
        $multi = OperatingCompany::create(['name' => 'شركة متعددة الفروع', 'is_active' => true]);
        foreach (['RUH', 'JED'] as $code) {
            OperatingBranch::create(['operating_company_id' => $multi->id, 'name' => "فرع {$code}", 'code' => $code, 'is_active' => true]);
        }
        $multiOwner = User::create([
            'name' => 'مالك الشركة الثانية', 'email' => 'multi-owner@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER, 'is_active' => true, 'operating_company_id' => $multi->id,
        ]);
        $single = $this->operatingBranch->id;
        $now = now();

        // Vehicle assigned to the company-wide owner: the company's only branch.
        $assigned = DB::table('vehicles')->insertGetId(['plate' => 'MIG-1', 'assigned_user_id' => $this->owner->id, 'created_at' => $now, 'updated_at' => $now]);
        // Unassigned vehicle, but inspected by a technician of the company.
        $inspected = DB::table('vehicles')->insertGetId(['plate' => 'MIG-2', 'created_at' => $now, 'updated_at' => $now]);
        $inspection = DB::table('vehicle_inspections')->insertGetId([
            'inspection_no' => 'VINSP-MIG', 'vehicle_id' => $inspected, 'inspected_by' => $this->technician->id,
            'inspected_at' => $now, 'odometer_km' => 100, 'checklist' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Nothing says whose it is: ambiguous (two companies exist).
        $orphan = DB::table('vehicles')->insertGetId(['plate' => 'MIG-3', 'created_at' => $now, 'updated_at' => $now]);
        // Assigned to the owner of the two-branch company: ambiguous.
        $multiVehicle = DB::table('vehicles')->insertGetId(['plate' => 'MIG-4', 'assigned_user_id' => $multiOwner->id, 'created_at' => $now, 'updated_at' => $now]);

        $location = DB::table('stock_locations')->insertGetId(['type' => 'vehicle', 'name' => 'سيارة MIG-1', 'vehicle_id' => $assigned, 'created_at' => $now, 'updated_at' => $now]);
        $warehouse = DB::table('stock_locations')->insertGetId(['type' => 'warehouse', 'name' => 'مستودع بلا فرع', 'created_at' => $now, 'updated_at' => $now]);
        $vehicleDocument = DB::table('vehicle_documents')->insertGetId(['vehicle_id' => $assigned, 'type' => 'insurance', 'created_at' => $now, 'updated_at' => $now]);

        // HR: a branch-bound employee's document takes the employee's branch;
        // a company-wide employee's leave the company's only branch.
        $technicianDocument = DB::table('employee_documents')->insertGetId(['user_id' => $this->technician->id, 'type' => 'iqama', 'created_at' => $now, 'updated_at' => $now]);
        $ownerLeave = DB::table('employee_leaves')->insertGetId([
            'user_id' => $this->owner->id, 'type' => 'annual', 'starts_on' => $now->toDateString(), 'ends_on' => $now->toDateString(),
            'days' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $ownerLead = DB::table('sales_leads')->insertGetId(['lead_no' => 'LEAD-MIG-1', 'company_name' => 'فرصة المالك', 'owner_user_id' => $this->owner->id, 'created_at' => $now, 'updated_at' => $now]);
        $multiLead = DB::table('sales_leads')->insertGetId(['lead_no' => 'LEAD-MIG-2', 'company_name' => 'فرصة غامضة', 'owner_user_id' => $multiOwner->id, 'created_at' => $now, 'updated_at' => $now]);
        // A row that already has a branch is never touched.
        $kept = DB::table('sales_leads')->insertGetId(['lead_no' => 'LEAD-MIG-3', 'company_name' => 'فرصة لها فرع', 'owner_user_id' => $multiOwner->id, 'operating_branch_id' => $single, 'created_at' => $now, 'updated_at' => $now]);

        (require database_path('migrations/2026_10_07_180000_assign_branchless_records_to_a_branch.php'))->up();

        $branch = fn (string $table, int $id) => DB::table($table)->where('id', $id)->value('operating_branch_id');

        $this->assertSame($single, $branch('vehicles', $assigned));
        $this->assertSame($single, $branch('vehicles', $inspected));
        $this->assertSame($single, $branch('vehicle_inspections', $inspection));
        $this->assertSame($single, $branch('stock_locations', $location), 'follows its vehicle');
        $this->assertSame($single, $branch('vehicle_documents', $vehicleDocument), 'follows its vehicle');
        $this->assertSame($this->technician->operating_branch_id, $branch('employee_documents', $technicianDocument));
        $this->assertSame($single, $branch('employee_leaves', $ownerLeave));
        $this->assertSame($single, $branch('sales_leads', $ownerLead));
        $this->assertSame($single, $branch('sales_leads', $kept));

        $this->assertNull($branch('vehicles', $orphan), 'no derivable company');
        $this->assertNull($branch('vehicles', $multiVehicle), 'company with two branches');
        $this->assertNull($branch('stock_locations', $warehouse));
        $this->assertNull($branch('sales_leads', $multiLead));
    }

    public function test_with_a_single_company_even_unrelated_rows_are_unambiguous(): void
    {
        $now = now();
        $vehicle = DB::table('vehicles')->insertGetId(['plate' => 'MIG-SOLO', 'created_at' => $now, 'updated_at' => $now]);
        $warehouse = DB::table('stock_locations')->insertGetId(['type' => 'warehouse', 'name' => 'مستودع وحيد', 'created_at' => $now, 'updated_at' => $now]);

        (require database_path('migrations/2026_10_07_180000_assign_branchless_records_to_a_branch.php'))->up();

        $this->assertSame($this->operatingBranch->id, DB::table('vehicles')->where('id', $vehicle)->value('operating_branch_id'));
        $this->assertSame($this->operatingBranch->id, DB::table('stock_locations')->where('id', $warehouse)->value('operating_branch_id'));
    }
}

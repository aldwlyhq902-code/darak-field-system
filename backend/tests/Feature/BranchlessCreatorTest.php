<?php

namespace Tests\Feature;

use App\Models\EmployeeDocument;
use App\Models\EmployeeLeave;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\SalesLead;
use App\Models\SalesLeadAttachment;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenanceOrder;
use App\Support\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: OperatingBranchScope shows these tables only through their
 * branch, and a company-wide user (no branch — the owner) wrote them with
 * operating_branch_id NULL, so the record they had just created vanished for
 * everyone, themselves included. Each table is created here through its real
 * panel path by the company-wide owner, then found in its list.
 */
class BranchlessCreatorTest extends DarakTestCase
{
    private const BRANCH_TABLES = [
        'vehicles', 'stock_locations', 'employee_documents', 'employee_leaves', 'vehicle_documents',
        'vehicle_maintenance_orders', 'vehicle_inspections', 'sales_leads',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PrivateFiles::diskName());
        $this->assertNull($this->owner->operating_branch_id, 'the fixture owner works company-wide');
    }

    /** After every test (a failure here still lets tearDown roll the database back). */
    protected function assertPostConditions(): void
    {
        foreach (self::BRANCH_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->whereNull('operating_branch_id')->count(), "{$table}: no branchless row is ever written");
        }
    }

    public function test_vehicle_and_its_stock_location(): void
    {
        $vehicle = $this->ownerCreatesVehicle('ب ر ن 1001');

        $this->assertSame($this->operatingBranch->id, $vehicle->operating_branch_id, 'the company\'s only branch');
        $this->actingAs($this->owner, 'web')->get(route('panel.fleet'))->assertOk()
            ->assertViewHas('vehicles', fn ($page) => $page->getCollection()->contains('id', $vehicle->id))
            ->assertSee('ب ر ن 1001');

        $location = StockLocation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->sole();
        $this->assertSame($this->operatingBranch->id, $location->operating_branch_id);
        $this->get(route('panel.inventory'))->assertOk()
            ->assertViewHas('locations', fn ($locations) => collect($locations)->contains(fn ($row) => $row['location']->id === $location->id))
            ->assertSee('سيارة ب ر ن 1001');
    }

    public function test_vehicle_document(): void
    {
        $vehicle = $this->ownerCreatesVehicle('ب ر ن 1002');

        $this->post(route('panel.fleet.document', $vehicle), [
            'type' => 'insurance', 'document_number' => 'POL-BRANCHLESS',
            'expires_on' => now()->addDays(20)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $document = VehicleDocument::withoutGlobalScopes()->where('document_number', 'POL-BRANCHLESS')->sole();
        $this->get(route('panel.fleet'))->assertOk()
            ->assertViewHas('expiringDocuments', fn ($page) => $page->getCollection()->contains('id', $document->id));
    }

    public function test_vehicle_maintenance_order(): void
    {
        $vehicle = $this->ownerCreatesVehicle('ب ر ن 1003');

        $this->post(route('panel.fleet.maintenance', $vehicle), [
            'type' => 'oil', 'priority' => 'normal', 'description' => 'تغيير زيت دوري',
            'opened_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $order = VehicleMaintenanceOrder::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->sole();
        $this->get(route('panel.fleet'))->assertOk()
            ->assertViewHas('openOrders', fn ($page) => $page->getCollection()->contains('id', $order->id))
            ->assertSee($order->order_no);
    }

    public function test_vehicle_inspection(): void
    {
        $vehicle = $this->ownerCreatesVehicle('ب ر ن 1004');

        $this->post(route('panel.fleet.inspection', $vehicle), [
            'odometer_km' => 12000, 'tires' => 1, 'brakes' => 1, 'lights' => 1, 'fluids' => 1, 'body' => 1, 'cleanliness' => 1,
        ])->assertRedirect();

        $inspection = VehicleInspection::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->sole();
        $this->get(route('panel.fleet'))->assertOk()
            ->assertViewHas('recentInspections', fn ($page) => $page->getCollection()->contains('id', $inspection->id));
    }

    public function test_employee_document_of_a_company_wide_employee(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.hr.document', $this->owner), [
            'type' => 'iqama', 'document_number' => 'IQ-OWNER-1',
            'expires_on' => now()->addDays(30)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $document = EmployeeDocument::withoutGlobalScopes()->where('document_number', 'IQ-OWNER-1')->sole();
        $this->assertSame($this->operatingBranch->id, $document->operating_branch_id);
        $this->get(route('panel.hr'))->assertOk()
            ->assertViewHas('expiringDocuments', fn ($page) => $page->getCollection()->contains('id', $document->id));
    }

    public function test_employee_leave_of_a_company_wide_employee(): void
    {
        $headOffice = $this->companyWideAdmin('head-office@test.local');

        $this->actingAs($this->owner, 'web')->post(route('panel.hr.leave'), [
            'user_id' => $headOffice->id, 'type' => 'emergency',
            'starts_on' => today()->addDays(3)->toDateString(), 'ends_on' => today()->addDays(4)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $leave = EmployeeLeave::withoutGlobalScopes()->where('user_id', $headOffice->id)->sole();
        $this->get(route('panel.hr'))->assertOk()
            ->assertViewHas('recentLeaves', fn ($page) => $page->getCollection()->contains('id', $leave->id))
            ->assertViewHas('pendingLeaves', fn ($page) => $page->getCollection()->contains('id', $leave->id));
    }

    public function test_sales_lead_and_attachment_from_the_sales_app(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('sales.leads.store'), [
            'company_name' => 'مطعم المالك المباشر', 'estimated_value' => 30000, 'source' => 'direct',
            'next_action_on' => today()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $lead = SalesLead::withoutGlobalScopes()->where('company_name', 'مطعم المالك المباشر')->sole();
        $this->assertSame($this->operatingBranch->id, $lead->operating_branch_id);

        $this->post(route('sales.leads.attachment', $lead), [
            'file' => UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $this->assertSame(1, SalesLeadAttachment::query()->where('sales_lead_id', $lead->id)->count(), 'the attachment is visible through its lead');

        $this->get(route('sales.home'))->assertOk()
            ->assertViewHas('leads', fn ($leads) => $leads->contains('id', $lead->id))
            ->assertViewHas('calendar', fn ($calendar) => $calendar->flatten()->contains('id', $lead->id))
            ->assertDontSee('name="operating_branch_id"', false);
    }

    public function test_sales_lead_from_administration(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.admin.lead'), [
            'company_name' => 'فرصة الإدارة', 'stage' => 'new', 'estimated_value' => 5000,
        ])->assertRedirect()->assertSessionHas('ok');

        $lead = SalesLead::withoutGlobalScopes()->where('company_name', 'فرصة الإدارة')->sole();
        $this->get(route('panel.admin-operations'))->assertOk()
            ->assertViewHas('leads', fn ($page) => $page->getCollection()->contains('id', $lead->id));
    }

    public function test_with_several_branches_the_form_asks_and_the_choice_is_validated(): void
    {
        $second = OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'فرع جدة', 'code' => 'JED', 'is_active' => true,
        ]);
        $foreignCompany = OperatingCompany::create(['name' => 'شركة أخرى', 'is_active' => true]);
        $foreign = OperatingBranch::create([
            'operating_company_id' => $foreignCompany->id, 'name' => 'فرع غريب', 'code' => 'FOR', 'is_active' => true,
        ]);
        $lead = ['company_name' => 'مطعم متعدد الفروع', 'estimated_value' => 1000, 'source' => 'direct'];

        // The sales app offers the branch select, preselecting the head office.
        $this->actingAs($this->owner, 'web')->get(route('sales.home'))->assertOk()
            ->assertSee('name="operating_branch_id" required', false)
            ->assertSee('فرع جدة')
            ->assertViewHas('branchDefault', $this->operatingBranch->id);

        // No choice: asked to choose, nothing written.
        $this->post(route('sales.leads.store'), $lead)->assertSessionHasErrors('operating_branch_id');
        // Another company's branch: refused.
        $this->post(route('sales.leads.store'), $lead + ['operating_branch_id' => $foreign->id])->assertSessionHasErrors('operating_branch_id');
        $this->assertSame(0, SalesLead::withoutGlobalScopes()->where('company_name', 'مطعم متعدد الفروع')->count());

        // Its own second branch: filed there and visible.
        $this->post(route('sales.leads.store'), $lead + ['operating_branch_id' => $second->id])->assertSessionHas('ok');
        $created = SalesLead::withoutGlobalScopes()->where('company_name', 'مطعم متعدد الفروع')->sole();
        $this->assertSame($second->id, $created->operating_branch_id);
        $this->get(route('sales.home'))->assertViewHas('leads', fn ($leads) => $leads->contains('id', $created->id));

        // Vehicles: the select has no "no branch" option any more.
        $this->get(route('panel.fleet'))->assertOk()->assertDontSee('الإدارة العامة');
        $this->post(route('panel.fleet.vehicle'), ['plate' => 'ب ر ن 2001', 'operating_branch_id' => $foreign->id])
            ->assertSessionHasErrors('operating_branch_id');
        $this->post(route('panel.fleet.vehicle'), ['plate' => 'ب ر ن 2001'])->assertSessionHasErrors('operating_branch_id');
        $this->post(route('panel.fleet.vehicle'), ['plate' => 'ب ر ن 2001', 'operating_branch_id' => $second->id])->assertSessionHas('ok');
        $this->assertSame($second->id, Vehicle::withoutGlobalScopes()->where('plate', 'ب ر ن 2001')->value('operating_branch_id'));

        // HR: a company-wide employee's document takes the chosen branch.
        $this->post(route('panel.hr.document', $this->owner), ['type' => 'passport', 'document_number' => 'P-1'])
            ->assertSessionHasErrors('operating_branch_id');
        $this->post(route('panel.hr.document', $this->owner), ['type' => 'passport', 'document_number' => 'P-1', 'operating_branch_id' => $second->id])
            ->assertSessionHas('ok');
        $this->assertSame($second->id, EmployeeDocument::withoutGlobalScopes()->where('document_number', 'P-1')->value('operating_branch_id'));
        // A branch-bound employee's records follow the employee, whatever is chosen.
        $this->post(route('panel.hr.document', $this->technician), ['type' => 'passport', 'document_number' => 'P-2', 'operating_branch_id' => $second->id])
            ->assertSessionHas('ok');
        $this->assertSame($this->operatingBranch->id, EmployeeDocument::withoutGlobalScopes()->where('document_number', 'P-2')->value('operating_branch_id'));
    }

    public function test_a_branch_bound_creator_still_stamps_their_own_branch(): void
    {
        $other = OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'فرع الدمام', 'code' => 'DMM', 'is_active' => true,
        ]);
        $marketer = User::create([
            'name' => 'مسوق الفرع', 'email' => 'branch-marketer@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'permissions' => ['sales'], 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $this->operatingBranch->id,
        ]);
        $marketer->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($marketer, 'web')->get(route('sales.home'))->assertOk()->assertDontSee('name="operating_branch_id"', false);
        $this->post(route('sales.leads.store'), [
            'company_name' => 'فرصة المسوق', 'estimated_value' => 100, 'source' => 'field', 'operating_branch_id' => $other->id,
        ])->assertSessionHas('ok');

        $this->assertSame($this->operatingBranch->id, SalesLead::withoutGlobalScopes()->where('company_name', 'فرصة المسوق')->value('operating_branch_id'));
    }

    public function test_a_vehicle_cannot_be_made_branchless_and_its_location_follows_it(): void
    {
        $vehicle = $this->ownerCreatesVehicle('ب ر ن 3001');
        $second = OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'فرع مكة', 'code' => 'MKK', 'is_active' => true,
        ]);

        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'vehicle', 'entity_id' => $vehicle->id])
            ->assertSessionHasErrors('operating_branch_id');
        $this->assertSame($this->operatingBranch->id, $vehicle->refresh()->operating_branch_id);

        $this->post(route('panel.admin.assign-branch'), ['entity_type' => 'vehicle', 'entity_id' => $vehicle->id, 'operating_branch_id' => $second->id])
            ->assertSessionHas('ok');
        $this->assertSame($second->id, $vehicle->refresh()->operating_branch_id);
        $this->assertSame($second->id, StockLocation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->value('operating_branch_id'));
    }

    private function ownerCreatesVehicle(string $plate): Vehicle
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.fleet.vehicle'), [
            'plate' => $plate, 'make' => 'تويوتا', 'model' => 'هايلكس', 'year' => 2024, 'current_odometer_km' => 10000,
        ])->assertRedirect()->assertSessionHas('ok');

        return Vehicle::withoutGlobalScopes()->where('plate', $plate)->sole();
    }

    private function companyWideAdmin(string $email): User
    {
        return User::create([
            'name' => 'موظف الإدارة العامة', 'email' => $email, 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
        ]);
    }
}

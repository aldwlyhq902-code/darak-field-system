<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\Custody;
use App\Models\MaintenancePlan;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Quotation;
use App\Models\RequestForQuotation;
use App\Models\Site;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\StockReservation;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleOutage;
use App\Models\Visit;
use App\Services\DispatchSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\DarakTestCase;

/**
 * H1/H2: nothing may pick a person, a branch or a record of another tenant —
 * not a panel form, not the API, and not a scheduled command running without
 * a signed-in user (where no global scope applies at all).
 */
class TenantIsolationFindingsTest extends DarakTestCase
{
    public function test_reassignment_command_never_moves_a_visit_to_another_tenants_technician(): void
    {
        $foreign = $this->foreignTenant();
        [, $siblingTechnician] = $this->siblingBranch();
        $start = CarbonImmutable::tomorrow()->setTime(9, 0);
        $this->visit->forceFill(['scheduled_start' => $start, 'scheduled_end' => $start->addHour()])->save();
        TechnicianAbsence::create([
            'user_id' => $this->technician->id, 'starts_on' => $start->toDateString(), 'ends_on' => $start->toDateString(),
            'reason' => 'sick', 'status' => 'approved', 'approved_by' => $this->owner->id,
        ]);

        $this->artisan('darak:reassign-unavailable')->assertSuccessful();

        // The only free technicians belong to another company or another branch:
        // the visit is released for a supervisor rather than handed across.
        $assigned = $this->visit->fresh()->assigned_user_id;
        $this->assertNotContains($assigned, [$foreign['technician']->id, $siblingTechnician->id]);
        $this->assertNull($assigned);
    }

    public function test_dispatch_suggestions_and_conflicts_stay_inside_the_visit_tenant(): void
    {
        $foreign = $this->foreignTenant();
        [, $siblingTechnician] = $this->siblingBranch();
        $this->visit->forceFill(['assigned_user_id' => null])->save();
        $this->technician->forceFill(['shift_start' => null, 'shift_end' => null])->save();

        $suggested = app(DispatchSuggestionService::class)->suggest($this->visit->fresh(), 10)
            ->map(fn (array $row) => $row['technician']->id)->all();

        $this->assertContains($this->technician->id, $suggested);
        $this->assertNotContains($foreign['technician']->id, $suggested);
        $this->assertNotContains($siblingTechnician->id, $suggested);
        $this->assertNotSame([], app(DispatchSuggestionService::class)->conflicts($foreign['technician'], $this->visit->fresh()));
    }

    public function test_board_and_api_refuse_a_technician_from_another_branch(): void
    {
        [, $siblingTechnician] = $this->siblingBranch();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.visit.assign', $this->visit), ['user_id' => $siblingTechnician->id])
            ->assertSessionHas('err');
        $this->assertSame($this->technician->id, $this->visit->fresh()->assigned_user_id);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)
            ->postJson("/api/v1/visits/{$this->visit->id}/assign", ['user_id' => $siblingTechnician->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSIGNMENT_BLOCKED');
        $this->assertSame($this->technician->id, $this->visit->fresh()->assigned_user_id);
    }

    public function test_new_visit_and_preventive_plan_cannot_name_a_technician_outside_the_client_tenant(): void
    {
        $foreign = $this->foreignTenant();
        [, $siblingTechnician] = $this->siblingBranch();

        $this->actingAs($this->owner, 'web')->post(route('panel.client.visit', $this->client), [
            'site_id' => $this->site->id, 'type' => 'reactive', 'title' => 'Cross tenant',
            'scheduled_start' => now()->addDay()->toDateTimeString(), 'assigned_user_id' => $foreign['technician']->id,
        ])->assertSessionHasErrors('assigned_user_id');
        $this->assertFalse(Visit::withoutGlobalScopes()->where('assigned_user_id', $foreign['technician']->id)->exists());

        $this->post(route('panel.maintenance.store'), [
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'title' => 'Plan',
            'frequency_days' => 30, 'duration_minutes' => 60, 'next_due_on' => now()->addWeek()->toDateString(),
            'preferred_user_id' => $siblingTechnician->id,
        ])->assertSessionHasErrors('preferred_user_id');
        $this->assertSame(0, MaintenancePlan::withoutGlobalScopes()->count());
    }

    public function test_panel_rosters_do_not_list_another_company_people_or_branches(): void
    {
        $foreign = $this->foreignTenant();

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.operations', ['tab' => 'resources']))
            ->assertOk()->assertSee($this->technician->name)->assertDontSee($foreign['technician']->name);
        $this->get(route('panel.fleet'))
            ->assertOk()->assertSee($this->operatingBranch->name)->assertDontSee($foreign['branch']->name);
        $this->get(route('sales.home'))
            ->assertOk()->assertDontSee($foreign['admin']->name);
    }

    public function test_company_wide_owner_is_never_branded_with_another_tenant(): void
    {
        $company = OperatingCompany::create(['name' => 'Second Tenant Co', 'currency' => 'SAR', 'is_active' => true]);
        $owner = User::create([
            'name' => 'Second Owner', 'email' => 'second-owner@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER, 'is_active' => true, 'operating_company_id' => $company->id,
        ]);
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        // The fixture company is the oldest row; it used to be shown to every
        // company-wide owner.
        $this->actingAs($owner, 'web')->get(route('panel.board'))
            ->assertOk()->assertSee('Second Tenant Co')->assertDontSee($this->operatingCompany->name);
    }

    public function test_commission_generator_never_credits_another_company_user(): void
    {
        $foreign = $this->foreignTenant();
        CommissionRule::create([
            'name' => 'Per visit', 'applies_to_role' => User::ROLE_TECHNICIAN, 'basis' => 'completed_visit',
            'rate' => 0, 'fixed_amount' => 10, 'is_active' => true,
        ]);
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        $misassigned = $this->visit->replicate()->forceFill(['assigned_user_id' => $foreign['technician']->id]);
        $misassigned->save();

        $this->artisan('darak:commissions-generate')->assertSuccessful();

        $this->assertTrue(CommissionEntry::where('visit_id', $this->visit->id)->where('user_id', $this->technician->id)->exists());
        $this->assertFalse(CommissionEntry::where('user_id', $foreign['technician']->id)->exists());
    }

    public function test_operations_fleet_and_maintenance_forms_cannot_write_against_foreign_records(): void
    {
        $foreign = $this->foreignTenant();
        $this->actingAs($this->owner, 'web');

        $this->post(route('panel.operations.outage'), [
            'vehicle_id' => $foreign['vehicle']->id, 'starts_at' => now()->toDateTimeString(), 'reason' => 'Sabotage',
        ])->assertNotFound();
        $this->post(route('panel.admin.expense'), [
            'vehicle_id' => $foreign['vehicle']->id, 'category' => 'fuel', 'amount' => 100, 'incurred_on' => today()->toDateString(),
        ])->assertNotFound();
        $this->post(route('panel.maintenance.store'), [
            'client_id' => $foreign['client']->id, 'site_id' => $foreign['site']->id, 'title' => 'Foreign plan',
            'frequency_days' => 30, 'duration_minutes' => 60, 'next_due_on' => now()->addWeek()->toDateString(),
        ])->assertNotFound();
        $this->post(route('panel.team.custody.issue'), [
            'user_id' => $this->technician->id, 'item_type' => 'stock', 'item_name' => 'Spare kit',
            'condition_out' => 'new', 'stock_location_id' => $foreign['warehouse']->id,
        ])->assertNotFound();

        $this->assertSame(0, VehicleOutage::withoutGlobalScopes()->count());
        $this->assertSame(0, VehicleExpense::withoutGlobalScopes()->count());
        $this->assertSame(0, MaintenancePlan::withoutGlobalScopes()->count());
        $this->assertSame(0, Custody::withoutGlobalScopes()->count());
        $this->assertSame('available', Vehicle::withoutGlobalScopes()->find($foreign['vehicle']->id)->operational_status ?? 'available');
    }

    public function test_commercial_and_contract_forms_cannot_reference_foreign_clients_or_sites(): void
    {
        $foreign = $this->foreignTenant();
        $this->actingAs($this->owner, 'web');

        $this->post(route('panel.quotation.store'), [
            'client_id' => $foreign['client']->id, 'site_ids' => [$foreign['site']->id], 'title' => 'Foreign quote',
            'package_code' => 'basic', 'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'starts_on' => today()->toDateString(), 'valid_until' => today()->addWeek()->toDateString(),
            'service_window_start' => '07:00', 'service_window_end' => '23:00', 'sla_minutes' => 240,
        ])->assertNotFound();
        $this->assertSame(0, Quotation::withoutGlobalScopes()->count());

        $this->post(route('panel.client.contract', $this->client), [
            'package_code' => 'basic', 'price_amount' => 1000, 'starts_on' => today()->toDateString(), 'sla_minutes' => 240,
            'service_window_start' => '07:00', 'service_window_end' => '23:00', 'site_ids' => [$foreign['site']->id],
        ])->assertSessionHasErrors('site_ids.0');
        $this->assertSame(1, Contract::withoutGlobalScopes()->count());
    }

    public function test_inventory_and_procurement_cannot_touch_a_foreign_stock_location(): void
    {
        $foreign = $this->foreignTenant();
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $foreign['warehouse']->id);
        $foreignMoves = StockMove::count();
        $this->actingAs($this->owner, 'web');

        $this->post(route('panel.inventory.receipt'), [
            'part_id' => $this->part->id, 'qty' => 3, 'to_location_id' => $foreign['warehouse']->id,
        ])->assertNotFound();
        $this->post(route('panel.inventory.adjust'), [
            'part_id' => $this->part->id, 'location_id' => $foreign['warehouse']->id, 'counted_qty' => 0, 'reason' => 'wipe',
        ])->assertNotFound();
        $this->post(route('panel.procurement.rfq'), [
            'destination_location_id' => $foreign['warehouse']->id, 'response_due_on' => today()->addWeek()->toDateString(),
            'items' => [['part_id' => $this->part->id, 'qty' => 2]],
        ])->assertNotFound();
        $this->post(route('panel.procurement.reserve', $this->visit), [
            'part_id' => $this->part->id, 'stock_location_id' => $foreign['warehouse']->id, 'qty' => 2,
        ])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/receipt', [
            'idempotency_key' => (string) Str::uuid(), 'part_id' => $this->part->id, 'qty' => 1,
            'to_location_id' => $foreign['warehouse']->id,
        ])->assertNotFound();

        $this->assertSame($foreignMoves, StockMove::withoutGlobalScopes()->count());
        $this->assertSame(0, RequestForQuotation::withoutGlobalScopes()->count());
        $this->assertSame(0, StockReservation::withoutGlobalScopes()->count());
    }

    public function test_inventory_service_rejects_foreign_or_cross_company_movements_for_any_caller(): void
    {
        $foreign = $this->foreignTenant();
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);

        // No signed-in user (a command): still never one movement across companies.
        try {
            $this->inventory()->loadVehicle((string) Str::uuid(), $this->part->id, 1, $this->warehouse->id, $foreign['vehicleLocation']->id);
            $this->fail('A movement between two companies was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('شركتين', $e->getMessage());
        }

        // A signed-in operator cannot reach a location outside their tenant.
        $this->actingAs($this->owner, 'web');
        try {
            $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 1, $foreign['warehouse']->id);
            $this->fail('A receipt into another company location was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('خارج نطاق', $e->getMessage());
        }

        $this->assertSame(1, StockMove::withoutGlobalScopes()->count());
    }

    /** @return array{0: OperatingBranch, 1: User} A second branch of the fixture company. */
    private function siblingBranch(): array
    {
        $branch = OperatingBranch::create([
            'operating_company_id' => $this->operatingCompany->id, 'name' => 'Sibling Branch', 'code' => 'SIB', 'is_active' => true,
        ]);
        $technician = User::create([
            'name' => 'Sibling Technician', 'email' => 'sibling-tech@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_TECHNICIAN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $branch->id,
        ]);

        return [$branch, $technician];
    }

    /** @return array<string, mixed> */
    private function foreignTenant(): array
    {
        $company = OperatingCompany::create(['name' => 'Foreign Co', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create([
            'operating_company_id' => $company->id, 'name' => 'Foreign Branch', 'code' => 'FRN', 'is_active' => true,
        ]);
        $technician = User::create([
            'name' => 'Foreign Technician', 'email' => 'foreign-tech@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_TECHNICIAN, 'is_active' => true,
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id,
        ]);
        $admin = User::create([
            'name' => 'Foreign Sales Admin', 'email' => 'foreign-admin@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'permissions' => ['sales'],
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id,
        ]);
        $client = Client::withoutGlobalScopes()->create([
            'name' => 'Foreign Client', 'category' => 'restaurant', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $site = Site::withoutGlobalScopes()->create(['client_id' => $client->id, 'name' => 'Foreign Site']);
        $vehicle = Vehicle::withoutGlobalScopes()->create([
            'plate' => 'FRN-1', 'internal_code' => 'F1', 'assigned_user_id' => $technician->id,
            'operating_branch_id' => $branch->id, 'is_active' => true, 'operational_status' => 'available',
        ]);
        $warehouse = StockLocation::withoutGlobalScopes()->create([
            'type' => StockLocation::TYPE_WAREHOUSE, 'name' => 'Foreign Warehouse',
            'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $vehicleLocation = StockLocation::withoutGlobalScopes()->create([
            'type' => StockLocation::TYPE_VEHICLE, 'name' => 'Foreign Van', 'vehicle_id' => $vehicle->id,
            'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);

        return compact('company', 'branch', 'technician', 'admin', 'client', 'site', 'vehicle', 'warehouse', 'vehicleLocation');
    }
}

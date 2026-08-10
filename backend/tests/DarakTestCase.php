<?php

namespace Tests;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Device;
use App\Models\Part;
use App\Models\Site;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Shared fixture builder. Deliberately explicit rather than factory-driven so the
 * numbers in each acceptance test can be read and checked by hand.
 */
abstract class DarakTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $technician;
    protected User $otherTechnician;
    protected Device $device;
    protected Client $client;
    protected Site $site;
    protected Asset $asset;
    protected Contract $contract;
    protected Visit $visit;
    protected Part $part;
    protected StockLocation $warehouse;
    protected StockLocation $vehicleStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_OWNER, 'is_active' => true,
        ]);

        $this->technician = User::create([
            'name' => 'Tech One', 'email' => 'tech1@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_TECHNICIAN,
            'specialties' => ['split_ac', 'electrical'],
            'shift_start' => '07:00', 'shift_end' => '15:00', 'is_active' => true,
        ]);

        $this->otherTechnician = User::create([
            'name' => 'Tech Two', 'email' => 'tech2@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_TECHNICIAN,
            'specialties' => ['split_ac'],
            'shift_start' => '15:00', 'shift_end' => '23:00', 'is_active' => true,
        ]);

        $this->device = Device::create([
            'user_id' => $this->technician->id,
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ]);

        $this->client = Client::create([
            'name' => 'Test Restaurant',
            'category' => 'restaurant',
            'established_on' => '2018-01-01',
        ]);

        $this->site = Site::create([
            'client_id' => $this->client->id,
            'name' => 'Main Branch',
            'lat' => 21.5810, 'lng' => 39.1650,
        ]);

        $this->asset = Asset::create([
            'site_id' => $this->site->id,
            'type' => 'split_ac',
            'name' => 'Dining Split Unit',
            'qr_code' => 'ASSET-TEST-1',
        ]);

        $this->contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_no' => 'DK-TEST-1',
            'package_code' => 'basic',
            'price_amount' => 1200,       // pre-VAT
            'vat_rate' => 0.15,
            'starts_on' => CarbonImmutable::now()->subMonth()->toDateString(),
            'service_window_start' => '07:00:00',
            'service_window_end' => '23:00:00',
            'sla_minutes' => 240,
            'status' => 'active',
        ]);
        $this->contract->sites()->attach($this->site->id);

        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-TEST-1',
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'contract_id' => $this->contract->id,
            'asset_id' => $this->asset->id,
            'type' => 'preventive',
            'title' => 'Monthly preventive visit',
            'reported_at' => CarbonImmutable::now()->subHour(),
            'sla_minutes_budget' => 240,
            'sla_due_at' => CarbonImmutable::now()->addHours(3),
            'status' => 'scheduled',
        ]);

        $this->visit = Visit::create([
            'work_order_id' => $workOrder->id,
            'site_id' => $this->site->id,
            'assigned_user_id' => $this->technician->id,
            'scheduled_start' => CarbonImmutable::now()->addHour(),
            'scheduled_end' => CarbonImmutable::now()->addHours(3),
            'state' => Visit::STATE_SCHEDULED,
            'state_changed_at' => CarbonImmutable::now(),
        ]);

        $this->part = Part::create([
            'sku' => 'CAP-35-440',
            'name' => 'Capacitor 35uF',
            'purchase_cost' => 38,
            'sale_price' => 95,
            'qr_code' => 'PART-CAP-35-440',
            'heat_sensitive' => true,
            'max_storage_temp_c' => 85,
            'reorder_level' => 2,
        ]);

        // Assigned to the technician: part movements are scoped to the vehicle the
        // device's user actually drives, so an unassigned van is refused.
        $vehicle = Vehicle::create([
            'plate' => 'TEST-1234',
            'internal_code' => 'V1',
            'assigned_user_id' => $this->technician->id,
        ]);
        $this->warehouse = StockLocation::create(['type' => StockLocation::TYPE_WAREHOUSE, 'name' => 'Central']);
        $this->vehicleStock = StockLocation::create([
            'type' => StockLocation::TYPE_VEHICLE,
            'name' => 'Vehicle 1',
            'vehicle_id' => $vehicle->id,
        ]);
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    /**
     * Switch bearer tokens inside one test.
     *
     * The auth guard caches the resolved user for the lifetime of the application
     * instance, so simply calling withToken() again keeps the PREVIOUS user and
     * silently invalidates any test that changes identity mid-way.
     */
    protected function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @param array<string, mixed> $overrides */
    protected function event(string $type, array $payload = [], array $overrides = []): array
    {
        return array_merge([
            'client_event_id' => (string) Str::uuid(),
            'visit_id' => $this->visit->id,
            'event_type' => $type,
            'payload' => $payload,
            'device_timestamp' => CarbonImmutable::now()->toIso8601String(),
            'monotonic_offset_ms' => 1000,
            'last_trusted_server_time' => CarbonImmutable::now()->subSecond()->toIso8601String(),
            'sequence' => 1,
            'source' => 'offline',
        ], $overrides);
    }
}

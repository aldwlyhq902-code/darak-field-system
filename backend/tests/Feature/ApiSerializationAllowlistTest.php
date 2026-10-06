<?php

namespace Tests\Feature;

use App\Models\StockReservation;
use App\Models\Visit;
use Tests\DarakTestCase;

/**
 * Review item 13 / contract C5: API responses are explicit allowlists. The phone
 * needs work details, never contract money, client registration numbers or
 * internal notes, staff cost or permissions.
 */
class ApiSerializationAllowlistTest extends DarakTestCase
{
    private const FORBIDDEN_KEYS = [
        '"contract"', '"price_amount"', '"vat_rate"', '"cr_number"', '"vat_number"', '"credit_limit"',
        '"notes"', '"hourly_cost"', '"permissions"', '"purchase_cost"', '"sale_price"', '"unit_cost"',
        '"two_factor', '"email"', '"phone"',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->forceFill([
            'cr_number' => '4030999999', 'vat_number' => '300999999900003',
            'notes' => 'internal: slow payer', 'credit_limit' => 50000,
        ])->save();
        $this->technician->forceFill(['hourly_cost' => 85, 'permissions' => ['operations']])->save();
        StockReservation::create([
            'visit_id' => $this->visit->id, 'part_id' => $this->part->id,
            'stock_location_id' => $this->vehicleStock->id, 'qty' => 1, 'status' => 'reserved',
        ]);
    }

    public function test_visit_detail_is_allowlisted(): void
    {
        $body = $this->actingAs($this->technician)
            ->getJson("/api/v1/visits/{$this->visit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->visit->id)
            ->assertJsonPath('data.site.client_name', 'Test Restaurant')
            ->assertJsonPath('data.work_order.number', 'WO-TEST-1')
            ->assertJsonPath('data.stock_reservations.0.part_name', 'Capacitor 35uF')
            ->getContent();

        $this->assertNoForbiddenData($body);
    }

    public function test_visit_list_is_allowlisted(): void
    {
        $body = $this->actingAs($this->owner)
            ->getJson('/api/v1/visits')
            ->assertOk()
            ->assertJsonPath('data.0.technician.name', 'Tech One')
            ->getContent();

        $this->assertNoForbiddenData($body);
    }

    public function test_transition_response_is_allowlisted(): void
    {
        $body = $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$this->visit->id}/transition", ['to' => Visit::STATE_EN_ROUTE])
            ->assertOk()
            ->assertJsonPath('data.state', Visit::STATE_EN_ROUTE)
            ->getContent();

        $this->assertNoForbiddenData($body);
    }

    public function test_bootstrap_keeps_every_key_the_app_reads_and_nothing_sensitive(): void
    {
        $response = $this->actingAs($this->technician)->getJson('/api/v1/sync/bootstrap')->assertOk();
        $visit = $response->json('visits.0');

        // Keys read by mobile/lib/core/local_db.dart (replaceVisits) and sync_engine.dart.
        $this->assertNotNull($response->json('server_time'));
        foreach (['id', 'state', 'scheduled_start', 'is_rework', 'on_site_seconds', 'site', 'work_order', 'sla', 'assets'] as $key) {
            $this->assertArrayHasKey($key, $visit);
        }
        foreach (['name', 'client_name', 'address', 'lat', 'lng', 'geofence_radius_m', 'access_notes'] as $key) {
            $this->assertArrayHasKey($key, $visit['site']);
        }
        foreach (['number', 'title', 'type'] as $key) {
            $this->assertArrayHasKey($key, $visit['work_order']);
        }
        $this->assertSame(['start' => '07:00:00', 'end' => '23:00:00'], $visit['sla']['service_window']);
        $this->assertArrayHasKey('due_at', $visit['sla']);
        $this->assertArrayHasKey('status', $visit['sla']);
        foreach (['id', 'name', 'type', 'location', 'qr_code', 'under_warranty'] as $key) {
            $this->assertArrayHasKey($key, $visit['assets'][0]);
        }

        $this->assertNoForbiddenData((string) $response->getContent());
    }

    public function test_me_is_allowlisted(): void
    {
        $response = $this->actingAs($this->technician)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame(
            ['id', 'name', 'role', 'trade', 'specialties', 'shift', 'server_time'],
            array_keys($response->json()),
        );
        $this->assertNoForbiddenData((string) $response->getContent());
    }

    private function assertNoForbiddenData(string $body): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertStringNotContainsString($key, $body, "response leaks {$key}");
        }
        foreach (['4030999999', '300999999900003', 'slow payer'] as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }
}

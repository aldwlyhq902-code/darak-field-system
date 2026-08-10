<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\StockMove;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Every case here was reachable with a single curl and a normal technician token.
 * The earlier suite hid them by only ever calling these endpoints as the owner.
 */
class ApiAuthorizationTest extends DarakTestCase
{
    public function test_technician_cannot_create_warehouse_stock(): void
    {
        // Phantom stock inflates balances, reorder signals and every margin
        // figure built on them — with no supervisor involved.
        $this->actingAs($this->technician)
            ->postJson('/api/v1/inventory/receipt', [
                'idempotency_key' => (string) Str::uuid(),
                'part_id' => $this->part->id,
                'qty' => 9999,
                'to_location_id' => $this->warehouse->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, StockMove::count());
    }

    public function test_technician_cannot_move_stock_between_arbitrary_locations(): void
    {
        $this->actingAs($this->technician)
            ->postJson('/api/v1/inventory/vehicle-load', [
                'idempotency_key' => (string) Str::uuid(),
                'part_id' => $this->part->id,
                'qty' => 1,
                'from_location_id' => $this->warehouse->id,
                'to_location_id' => $this->vehicleStock->id,
            ])
            ->assertStatus(403);
    }

    public function test_technician_cannot_read_the_central_warehouse_balances(): void
    {
        $this->actingAs($this->technician)
            ->getJson("/api/v1/inventory/locations/{$this->warehouse->id}/balances")
            ->assertStatus(403);
    }

    public function test_owner_retains_full_inventory_access(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/inventory/receipt', [
                'idempotency_key' => (string) Str::uuid(),
                'part_id' => $this->part->id,
                'qty' => 5,
                'to_location_id' => $this->warehouse->id,
            ])
            ->assertStatus(201);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/locations/{$this->warehouse->id}/balances")
            ->assertOk();
    }

    public function test_technician_cannot_export_company_wide_data(): void
    {
        foreach (['/api/v1/reports/visits.csv', '/api/v1/reports/stock-moves.csv', '/api/v1/reports/first-time-fix'] as $url) {
            $this->actingAs($this->technician)->get($url)->assertStatus(403);
        }
    }

    public function test_owner_can_still_export(): void
    {
        $this->actingAs($this->owner)->get('/api/v1/reports/visits.csv')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/v1/reports/first-time-fix')->assertOk();
    }

    /**
     * The nastiest of the three: without an ownership check a technician can
     * overwrite another technician's evidence with bytes of their choosing. The
     * sha256 check is no defence — the attacker supplies both the bytes and the
     * hash.
     */
    public function test_technician_cannot_touch_media_on_another_technicians_visit(): void
    {
        Storage::fake('local');

        $foreignVisit = $this->visitForOtherTechnician();

        $media = MediaFile::create([
            'visit_id' => $foreignVisit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'mime' => 'image/jpeg',
            'total_bytes' => 32,
            'upload_state' => 'pending',
        ]);

        $bytes = random_bytes(32);

        $this->actingAs($this->technician)
            ->getJson("/api/v1/media/{$media->client_media_id}/status")
            ->assertStatus(403);

        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$media->client_media_id}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)
            ->assertStatus(403);

        $this->actingAs($this->technician)
            ->postJson("/api/v1/media/{$media->client_media_id}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertStatus(403);

        $this->assertSame('pending', $media->refresh()->upload_state);
        $this->assertNull($media->original_hash, 'no planted evidence');
    }

    public function test_technician_can_still_upload_to_their_own_visit(): void
    {
        Storage::fake('local');

        $media = MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'mime' => 'image/jpeg',
            'total_bytes' => 16,
            'upload_state' => 'pending',
        ]);

        $bytes = random_bytes(16);

        $this->actingAs($this->technician)
            ->call('POST', "/api/v1/media/{$media->client_media_id}/chunk", [], [], [],
                ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)
            ->assertOk();

        $this->actingAs($this->technician)
            ->postJson("/api/v1/media/{$media->client_media_id}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertOk();
    }

    private function visitForOtherTechnician(): Visit
    {
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-' . Str::random(6),
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'type' => 'reactive',
            'title' => 'Foreign visit',
            'reported_at' => now(),
            'status' => 'scheduled',
        ]);

        return Visit::create([
            'work_order_id' => $workOrder->id,
            'site_id' => $this->site->id,
            'assigned_user_id' => $this->otherTechnician->id,
            'scheduled_start' => now()->addHours(5),
            'scheduled_end' => now()->addHours(7),
            'state' => Visit::STATE_SCHEDULED,
        ]);
    }
}

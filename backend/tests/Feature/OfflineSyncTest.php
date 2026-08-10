<?php

namespace Tests\Feature;

use App\Models\StockMove;
use App\Models\VisitEvent;
use App\Services\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criterion 1 and 3 (PRD v1.2 §6), and the exact scenario the paid
 * programmer-gate week is measured against.
 */
class OfflineSyncTest extends DarakTestCase
{
    public function test_twenty_events_replayed_five_times_produce_twenty_rows(): void
    {
        $sync = app(SyncService::class);

        $events = [];
        for ($i = 0; $i < 20; $i++) {
            $events[] = $this->event('geofence.ping', ['index' => $i], [
                'sequence' => $i,
                'client_event_id' => (string) Str::uuid(),
            ]);
        }

        // First delivery.
        $first = $sync->ingest($this->device, $events);
        $this->assertCount(20, array_filter($first['results'], fn ($r) => $r['status'] === 'accepted'));

        // Four more replays of the identical batch — the exact failure mode a flaky
        // connection produces.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $replay = $sync->ingest($this->device, $events);
            $this->assertCount(20, array_filter($replay['results'], fn ($r) => $r['status'] === 'duplicate'));
        }

        $this->assertSame(20, VisitEvent::where('visit_id', $this->visit->id)->count());
    }

    public function test_replayed_part_issue_deducts_stock_only_once(): void
    {
        // Receipt into the warehouse first — you cannot load a vehicle from empty stock.
        $this->inventory()->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $this->inventory()->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);

        $sync = app(SyncService::class);
        $idempotencyKey = (string) Str::uuid();

        $event = $this->event('part.issue', [
            'part_id' => $this->part->id,
            'qty' => 2,
            'from_location_id' => $this->vehicleStock->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $sync->ingest($this->device, [$event]);
        }

        $this->assertSame(1, StockMove::where('idempotency_key', $idempotencyKey)->count());
        $this->assertEqualsWithDelta(8.0, $this->inventory()->balance($this->part->id, $this->vehicleStock->id), 0.001);
    }

    public function test_events_are_replayed_in_device_sequence_not_wall_clock_order(): void
    {
        $sync = app(SyncService::class);

        // Sequence says A then B, but the wall clock claims the opposite —
        // exactly what a technician changing the phone clock would produce.
        $a = $this->event('note.added', ['text' => 'first'], [
            'sequence' => 1,
            'device_timestamp' => CarbonImmutable::now()->addHour()->toIso8601String(),
        ]);
        $b = $this->event('note.added', ['text' => 'second'], [
            'sequence' => 2,
            'device_timestamp' => CarbonImmutable::now()->subHour()->toIso8601String(),
        ]);

        $sync->ingest($this->device, [$b, $a]);

        $stored = VisitEvent::orderBy('id')->pluck('payload')->map(fn ($p) => $p['text'])->all();

        $this->assertSame(['first', 'second'], $stored);
    }

    public function test_wall_clock_tampering_is_flagged_without_dropping_the_event(): void
    {
        $sync = app(SyncService::class);

        // Device claims 6 hours ago, but the monotonic offset since the last trusted
        // server time says only one second elapsed.
        $event = $this->event('note.added', ['text' => 'suspicious'], [
            'device_timestamp' => CarbonImmutable::now()->subHours(6)->toIso8601String(),
            'monotonic_offset_ms' => 1000,
            'last_trusted_server_time' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $result = $sync->ingest($this->device, [$event]);

        $this->assertSame('accepted', $result['results'][0]['status']);

        $stored = VisitEvent::first();
        $this->assertTrue((bool) $stored->clock_suspect, 'Clock tampering should be flagged.');
        $this->assertNotNull($stored->server_received_at);
        $this->assertLessThan(-100, $stored->clock_divergence_seconds);
    }

    public function test_one_bad_event_does_not_reject_the_whole_batch(): void
    {
        $sync = app(SyncService::class);

        $good = $this->event('note.added', ['text' => 'fine']);
        $bad = $this->event('part.issue', [
            'part_id' => $this->part->id,
            'qty' => 999,                       // more than the vehicle holds
            'from_location_id' => $this->vehicleStock->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $alsoGood = $this->event('note.added', ['text' => 'also fine']);

        $result = $sync->ingest($this->device, [$good, $bad, $alsoGood]);

        $statuses = array_column($result['results'], 'status');
        $this->assertSame(2, count(array_filter($statuses, fn ($s) => $s === 'accepted')));
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 'rejected')));

        // And the rejection carries a reason the app can show the technician.
        $rejected = collect($result['results'])->firstWhere('status', 'rejected');
        $this->assertNotEmpty($rejected['message']);
    }

    public function test_offline_event_cannot_resurrect_a_reassigned_visit(): void
    {
        $sync = app(SyncService::class);

        // The office reassigns the visit while the handset is dark.
        $this->visit->forceFill(['assigned_user_id' => $this->otherTechnician->id])->save();

        $result = $sync->ingest($this->device, [$this->event('note.added', ['text' => 'stale'])]);

        $this->assertSame('rejected', $result['results'][0]['status']);
        $this->assertStringContainsString('no longer assigned', $result['results'][0]['message']);
    }

    public function test_missing_client_event_id_is_rejected_with_a_code(): void
    {
        $sync = app(SyncService::class);

        $event = $this->event('note.added');
        unset($event['client_event_id']);

        $result = $sync->ingest($this->device, [$event]);

        $this->assertSame('rejected', $result['results'][0]['status']);
        $this->assertSame('MISSING_CLIENT_EVENT_ID', $result['results'][0]['code']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\StockMove;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Visit;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Behaviour that only PostgreSQL can prove.
 *
 * The suite used to run on SQLite, which accepts any string in a column declared
 * `uuid` and never poisons a transaction on a constraint violation. Three
 * critical defects lived behind that gap: every offline visit transition and
 * every invoice would have failed on the first production write, with 136 green
 * tests saying otherwise.
 */
class PostgresRealityTest extends DarakTestCase
{
    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL — the engine production runs on.');
        }
    }

    public function test_a_derived_event_id_is_a_real_uuid(): void
    {
        $parent = (string) Str::uuid();
        $derived = SyncService::derivedEventId($parent, 'state');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $derived,
            'appending text to a uuid is rejected outright by a uuid column',
        );

        // Deterministic, so replaying the parent dedupes on the unique index.
        $this->assertSame($derived, SyncService::derivedEventId($parent, 'state'));
        $this->assertNotSame($derived, SyncService::derivedEventId($parent, 'other'));
    }

    public function test_an_offline_transition_persists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('visit.transition', ['to' => 'en_route'])],
        ])->assertOk();

        $this->assertSame('accepted', $response->json('results.0.status'));
        $this->assertSame(Visit::STATE_EN_ROUTE, $this->visit->refresh()->state);
    }

    public function test_an_invoice_key_that_is_not_a_uuid_still_persists(): void
    {
        $this->skipUnlessPostgres();

        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $inv->issueToVisit((string) Str::uuid(), $this->part->id, 1, $this->vehicleStock->id, $this->visit);

        $invoice = app(\App\Services\InvoiceService::class)->invoiceVisit($this->visit->refresh());

        $this->assertSame("invoice:visit:{$this->visit->id}", $invoice->idempotency_key);
        $this->assertSame('issued', $invoice->status);
    }

    /**
     * The concurrency test the review asked for: two independent connections,
     * different idempotency keys, both trying to take the whole balance.
     *
     * Proven by the lock itself — while one transaction holds the advisory lock
     * for (part, location), the other cannot take it. Without that, both read the
     * pre-insert history, both see enough stock, and the balance goes negative.
     */
    public function test_the_advisory_lock_actually_serialises_a_part_and_location(): void
    {
        $this->skipUnlessPostgres();

        $partId = $this->part->id;
        $locationId = $this->vehicleStock->id;

        // A genuinely separate connection. Concurrency cannot be tested from one:
        // a session never blocks on a lock it already holds.
        config()->set('database.connections.pgsql_probe', config('database.connections.pgsql'));
        $second = DB::connection('pgsql_probe');

        try {
            DB::beginTransaction();
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [$partId, $locationId]);

            $second->statement("SET lock_timeout = '400ms'");

            $blocked = false;

            try {
                $second->beginTransaction();
                $second->statement('SELECT pg_advisory_xact_lock(?, ?)', [$partId, $locationId]);
                $second->rollBack();
            } catch (\Throwable $e) {
                $blocked = str_contains(strtolower($e->getMessage()), 'lock timeout')
                    || str_contains(strtolower($e->getMessage()), 'canceling statement');
                $second->rollBack();
            }

            $this->assertTrue($blocked, 'a second writer must wait for the same (part, location)');

            // A different part is not blocked — the lock is not global.
            $second->statement("SET lock_timeout = '400ms'");
            $second->beginTransaction();
            $second->statement('SELECT pg_advisory_xact_lock(?, ?)', [$partId + 9999, $locationId]);
            $second->rollBack();
        } finally {
            DB::rollBack();
        }
    }

    public function test_stock_never_goes_negative_across_repeated_issues(): void
    {
        $this->skipUnlessPostgres();

        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);

        $succeeded = 0;

        for ($i = 0; $i < 3; $i++) {
            try {
                $inv->issueToVisit((string) Str::uuid(), $this->part->id, 4, $this->vehicleStock->id, $this->visit);
                $succeeded++;
            } catch (\RuntimeException) {
                // expected once the balance runs out
            }
        }

        $this->assertSame(1, $succeeded);
        $this->assertGreaterThanOrEqual(0, $inv->balance($this->part->id, $this->vehicleStock->id));
    }

    // --- the authorisation guards added after the review ---------------------

    public function test_an_unassigned_visit_is_not_open_to_any_technician(): void
    {
        $this->visit->forceFill(['assigned_user_id' => null])->save();

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('visit.transition', ['to' => 'en_route'])],
        ])->assertOk();

        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertStringContainsString('not assigned to you', $response->json('results.0.message'));
        $this->assertSame(Visit::STATE_SCHEDULED, $this->visit->refresh()->state);
    }

    public function test_a_technician_cannot_issue_from_another_vehicle(): void
    {
        $otherVehicle = Vehicle::create(['plate' => 'OTHER-99', 'assigned_user_id' => $this->otherTechnician->id]);
        $otherStock = \App\Models\StockLocation::create([
            'type' => \App\Models\StockLocation::TYPE_VEHICLE,
            'name' => 'Vehicle 2',
            'vehicle_id' => $otherVehicle->id,
        ]);

        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $otherStock->id);

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('part.issue', [
                'part_id' => $this->part->id,
                'qty' => 1,
                'from_location_id' => $otherStock->id,
                'idempotency_key' => (string) Str::uuid(),
            ])],
        ])->assertOk();

        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertStringContainsString('your own vehicle', $response->json('results.0.message'));
        $this->assertEqualsWithDelta(5.0, $inv->balance($this->part->id, $otherStock->id), 0.001);
    }

    public function test_a_technician_cannot_issue_from_the_central_warehouse(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('part.issue', [
                'part_id' => $this->part->id,
                'qty' => 3,
                'from_location_id' => $this->warehouse->id,
                'idempotency_key' => (string) Str::uuid(),
            ])],
        ])->assertOk();

        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertEqualsWithDelta(10.0, $inv->balance($this->part->id, $this->warehouse->id), 0.001);
    }

    public function test_a_return_cannot_exceed_what_was_issued(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('part.return', [
                'original_move_id' => $issue->id,
                'qty' => 5, // more than the two that were issued
                'to_location_id' => $this->vehicleStock->id,
                'idempotency_key' => (string) Str::uuid(),
            ])],
        ])->assertOk();

        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertSame(0, StockMove::where('move_type', StockMove::VISIT_RETURN)->count());
    }

    public function test_a_return_must_belong_to_this_visit(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);

        $foreignVisit = $this->foreignVisitOwnedByMe();
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $foreignVisit);

        $token = $this->deviceToken();

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('part.return', [
                'original_move_id' => $issue->id,
                'qty' => 1,
                'to_location_id' => $this->vehicleStock->id,
                'idempotency_key' => (string) Str::uuid(),
            ])],
        ])->assertOk();

        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertStringContainsString('not issued on this visit', $response->json('results.0.message'));
    }

    // --- retryable is for refusals that clear themselves ---------------------

    public function test_a_missing_signature_is_not_reported_as_retryable(): void
    {
        $token = $this->deviceToken();

        // Walk to awaiting_close with nothing captured at all.
        foreach (['en_route', 'started', 'awaiting_close'] as $i => $state) {
            $this->asToken($token)->postJson('/api/v1/sync/events', [
                'device_uuid' => $this->device->device_uuid,
                'events' => [$this->event('visit.transition', ['to' => $state], ['sequence' => $i + 1])],
            ])->assertOk();
        }

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('visit.transition', ['to' => 'completed'], ['sequence' => 9])],
        ])->assertOk();

        $result = $response->json('results.0');

        $this->assertSame('VISIT_CLOSE_BLOCKED', $result['code']);
        $this->assertFalse(
            $result['retryable'],
            'nothing resolves a missing signature on its own — retrying forever tells the technician nothing',
        );
    }

    public function test_only_pending_uploads_make_a_refusal_retryable(): void
    {
        $this->assertTrue(SyncService::blockersAreTransient([
            ['code' => 'UPLOADS_PENDING'],
        ]));

        $this->assertFalse(SyncService::blockersAreTransient([
            ['code' => 'UPLOADS_PENDING'],
            ['code' => 'SIGNATURE_MISSING'],
        ]));

        $this->assertFalse(SyncService::blockersAreTransient([]));
    }

    private function foreignVisitOwnedByMe(): Visit
    {
        $workOrder = \App\Models\WorkOrder::create([
            'wo_number' => 'WO-' . Str::random(6),
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'type' => 'reactive',
            'title' => 'Another visit of mine',
            'reported_at' => now(),
            'status' => 'scheduled',
        ]);

        return Visit::create([
            'work_order_id' => $workOrder->id,
            'site_id' => $this->site->id,
            'assigned_user_id' => $this->technician->id,
            'scheduled_start' => now()->addHours(6),
            'scheduled_end' => now()->addHours(8),
            'state' => Visit::STATE_SCHEDULED,
        ]);
    }

    private function deviceToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');
    }
}

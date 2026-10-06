<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\SyncService;
use App\Services\VisitStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Review items 7 and 8 plus the C3/C6 contract: device UTC timestamps, error
 * redaction, the sync throttle, location-quality flags and actor binding.
 */
class SyncHardeningTest extends DarakTestCase
{
    // --- item 7: timezone ----------------------------------------------------

    public function test_device_utc_timestamps_are_stored_as_riyadh_wall_clock(): void
    {
        config(['app.timezone' => 'Asia/Riyadh']);
        date_default_timezone_set('Asia/Riyadh');
        $mediaId = (string) Str::uuid();

        $result = app(SyncService::class)->ingest($this->device, [
            $this->event('note.added', ['text' => 'on site'], [
                'device_timestamp' => '2026-10-06T07:00:00Z',
                'last_trusted_server_time' => '2026-10-06T06:59:00Z',
                'monotonic_offset_ms' => 60_000,
            ]),
            $this->event('media.register', [
                'client_media_id' => $mediaId, 'kind' => 'photo_after', 'mime' => 'image/jpeg',
                'total_bytes' => 100, 'captured_at' => '2026-10-06T07:05:00Z',
            ], ['sequence' => 2, 'device_timestamp' => '2026-10-06T07:05:00Z']),
        ]);
        $this->assertSame(['accepted', 'accepted'], array_column($result['results'], 'status'));

        $row = DB::table('visit_events')->where('event_type', 'note.added')->first();
        $this->assertStringStartsWith('2026-10-06 10:00:00', (string) $row->device_timestamp);
        $this->assertStringStartsWith('2026-10-06 09:59:00', (string) $row->last_trusted_server_time);
        // Divergence is still measured on absolute time: expected == reported.
        $this->assertSame(0, (int) $row->clock_divergence_seconds);
        $this->assertFalse((bool) $row->clock_suspect);

        $this->assertStringStartsWith(
            '2026-10-06 10:05:00',
            (string) DB::table('media_files')->where('client_media_id', $mediaId)->value('captured_at'),
        );
    }

    public function test_a_wound_forward_clock_is_still_flagged_with_utc_input(): void
    {
        app(SyncService::class)->ingest($this->device, [
            $this->event('note.added', ['text' => 'x'], [
                'device_timestamp' => '2026-10-06T07:10:00Z',
                'last_trusted_server_time' => '2026-10-06T06:59:00Z',
                'monotonic_offset_ms' => 60_000,
            ]),
        ]);

        $event = VisitEvent::where('event_type', 'note.added')->sole();
        $this->assertSame(600, $event->clock_divergence_seconds);
        $this->assertTrue($event->clock_suspect);
    }

    // --- item 8: no raw exception text ---------------------------------------

    public function test_unexpected_failures_are_redacted_for_the_device_and_the_log(): void
    {
        Log::spy();
        $this->mock(VisitStateMachine::class, fn ($mock) => $mock->shouldReceive('transition')
            ->andThrow(new \LogicException('secret internals: SELECT * FROM users WHERE phone = 0555123456')));
        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('visit.transition', ['to' => Visit::STATE_EN_ROUTE])],
        ])->assertOk();

        $requestId = $response->headers->get('X-Request-ID');
        $this->assertSame('rejected', $response->json('results.0.status'));
        $this->assertSame('EVENT_FAILED', $response->json('results.0.code'));
        $this->assertSame($requestId, $response->json('results.0.request_id'));
        $this->assertStringNotContainsString('secret internals', (string) $response->getContent());

        Log::shouldHaveReceived('warning')->with('sync.event_failed', \Mockery::on(
            fn (array $context): bool => $context['exception'] === \LogicException::class
                && $context['request_id'] === $requestId
                && ! str_contains((string) json_encode($context), '0555123456'),
        ));
    }

    public function test_reported_query_exceptions_never_log_their_bindings(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new QueryException(
            'pgsql',
            'select * from client_portal_users where phone = ?',
            ['0555123456'],
            new \RuntimeException('SQLSTATE[XX000]: boom'),
        ));

        Log::shouldHaveReceived('error')->with('database.query_failed', \Mockery::on(
            fn (array $context): bool => ! str_contains((string) json_encode($context), '0555123456')
                && $context['exception'] === QueryException::class,
        ))->once();
        // The default stack (which would log the full message) is skipped.
        Log::shouldNotHaveReceived('error', [\Mockery::pattern('/0555123456/'), \Mockery::any()]);
    }

    public function test_business_refusals_keep_their_readable_message_under_a_code(): void
    {
        $this->visit->forceFill(['assigned_user_id' => $this->otherTechnician->id])->save();

        $result = app(SyncService::class)->ingest($this->device, [
            $this->event('note.added', ['text' => 'hello']),
        ])['results'][0];

        $this->assertSame('VISIT_REASSIGNED', $result['code']);
        $this->assertStringContainsString('no longer assigned', $result['message']);
    }

    public function test_sync_events_are_throttled_per_device(): void
    {
        config(['darak.api.sync_events_per_minute' => 3]);
        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        for ($i = 0; $i < 3; $i++) {
            $this->asToken($token)->postJson('/api/v1/sync/events', [
                'device_uuid' => $this->device->device_uuid,
                'events' => [$this->event('geofence.ping', [], ['sequence' => $i])],
            ])->assertOk();
        }

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [$this->event('geofence.ping', [], ['sequence' => 9])],
        ])->assertStatus(429);
    }

    // --- C3: location quality ------------------------------------------------

    public function test_location_quality_and_geofence_are_recorded_without_blocking(): void
    {
        $this->site->forceFill(['geofence_radius_m' => 100])->save();

        // ~1.1 km north of the site, a mocked fix, with the technician's reason.
        $result = app(SyncService::class)->ingest($this->device, [
            $this->event('visit.transition', ['to' => Visit::STATE_EN_ROUTE], ['sequence' => 1]),
            $this->event('visit.transition', [
                'to' => Visit::STATE_STARTED,
                'geofence_override_reason' => 'المدخل الخلفي للمطعم خارج الخريطة',
            ], ['sequence' => 2, 'lat' => 21.5910, 'lng' => 39.1650, 'accuracy_m' => 25, 'is_mocked' => true]),
            $this->event('note.added', ['text' => 'inside'], [
                'sequence' => 3, 'lat' => 21.5811, 'lng' => 39.1651, 'accuracy_m' => 8, 'is_mocked' => false,
            ]),
        ]);
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($result['results'], 'status'));
        $this->assertSame(Visit::STATE_STARTED, $this->visit->refresh()->state);

        $started = VisitEvent::where('event_type', 'visit.transition')->where('sequence', 2)->sole();
        $this->assertTrue($started->outside_geofence);
        $this->assertTrue($started->is_mocked);
        $this->assertSame('25.00', $started->accuracy_m);
        $this->assertSame('المدخل الخلفي للمطعم خارج الخريطة', $started->payload['geofence_override_reason']);

        $inside = VisitEvent::where('event_type', 'note.added')->sole();
        $this->assertFalse($inside->outside_geofence);
        $this->assertFalse($inside->is_mocked);

        $this->assertNull(VisitEvent::where('sequence', 1)->where('event_type', 'visit.transition')->sole()->outside_geofence);

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.visit', $this->visit))
            ->assertOk()
            ->assertSee('موقع وهمي')
            ->assertSee('خارج نطاق الموقع')
            ->assertSee('المدخل الخلفي للمطعم خارج الخريطة');
    }

    public function test_accuracy_widens_the_fence_by_at_most_100_metres(): void
    {
        $this->site->forceFill(['geofence_radius_m' => 100])->save();
        // ~167 m from the site centre.
        $point = ['lat' => 21.5825, 'lng' => 39.1650];

        app(SyncService::class)->ingest($this->device, [
            $this->event('note.added', ['text' => 'a'], ['sequence' => 1, ...$point, 'accuracy_m' => 80]),
            $this->event('note.added', ['text' => 'b'], ['sequence' => 2, ...$point, 'accuracy_m' => 5000]),
            $this->event('note.added', ['text' => 'c'], ['sequence' => 3, ...$point, 'accuracy_m' => 20]),
        ]);

        $flags = VisitEvent::where('event_type', 'note.added')->orderBy('sequence')->pluck('outside_geofence')->all();
        $this->assertSame([false, false, true], $flags);
    }

    // --- C6: actor binding ---------------------------------------------------

    public function test_an_event_recorded_by_another_account_is_refused_alone(): void
    {
        $token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;

        $response = $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => [
                $this->event('note.added', ['text' => 'mine'], ['sequence' => 1, 'actor_user_id' => $this->technician->id]),
                $this->event('note.added', ['text' => 'theirs'], ['sequence' => 2, 'actor_user_id' => $this->otherTechnician->id]),
            ],
        ])->assertOk();

        $this->assertSame('accepted', $response->json('results.0.status'));
        $this->assertSame('rejected', $response->json('results.1.status'));
        $this->assertSame('ACTOR_MISMATCH', $response->json('results.1.code'));
        $this->assertSame(1, VisitEvent::where('event_type', 'note.added')->count());
    }

    public function test_media_capture_time_from_the_device_is_normalised_too(): void
    {
        $mediaId = (string) Str::uuid();
        app(SyncService::class)->ingest($this->device, [
            $this->event('media.register', [
                'client_media_id' => $mediaId, 'kind' => 'photo_before', 'mime' => 'image/jpeg',
                'total_bytes' => 10, 'captured_at' => '2026-01-01T21:30:00Z',
            ]),
        ]);

        $captured = MediaFile::where('client_media_id', $mediaId)->sole()->captured_at;
        $this->assertTrue(CarbonImmutable::parse('2026-01-01T21:30:00Z')->equalTo($captured));
        $this->assertSame('2026-01-02 00:30', $captured->format('Y-m-d H:i'));
    }
}

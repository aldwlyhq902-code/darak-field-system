<?php

namespace Tests\Feature;

use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Billable hours are the basis of the whole revenue model, and they were being
 * computed from server receive times — so a full offline day, replayed in one
 * batch, reported roughly zero minutes on site.
 */
class OnSiteDurationTest extends DarakTestCase
{
    public function test_an_offline_day_replayed_in_one_batch_keeps_its_real_duration(): void
    {
        $token = $this->deviceToken();
        $base = CarbonImmutable::now()->subHours(6);

        // Field reality: started 08:00, paused 10:00, resumed 10:30, finished
        // 12:00 — three and a half hours on site, synced afterwards in one go.
        $chain = [
            ['to' => 'en_route', 'at' => $base],
            ['to' => 'started', 'at' => $base->addMinutes(15)],
            ['to' => 'paused', 'at' => $base->addMinutes(135)],   // 2h worked
            ['to' => 'started', 'at' => $base->addMinutes(165)],
            ['to' => 'awaiting_close', 'at' => $base->addMinutes(255)], // +1.5h
        ];

        $events = [];
        $sequence = 0;

        foreach ($chain as $step) {
            $events[] = [
                'client_event_id' => (string) Str::uuid(),
                'visit_id' => $this->visit->id,
                'event_type' => 'visit.transition',
                'payload' => ['to' => $step['to']],
                'device_timestamp' => $step['at']->toIso8601String(),
                'monotonic_offset_ms' => ++$sequence * 1000,
                'last_trusted_server_time' => $base->toIso8601String(),
                'sequence' => $sequence,
                'source' => 'offline',
            ];
        }

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => $events,
        ])->assertOk();

        $seconds = $this->visit->refresh()->on_site_seconds;

        // 120 + 90 = 210 minutes of started time.
        $this->assertEqualsWithDelta(
            210 * 60,
            $seconds,
            120,
            'a full offline day must keep its duration, not collapse to zero',
        );
    }

    public function test_a_forward_wound_device_clock_cannot_inflate_the_hours(): void
    {
        $token = $this->deviceToken();
        $now = CarbonImmutable::now();

        // The phone claims a segment three days long on a visit created minutes ago.
        $events = [
            $this->transitionEvent('en_route', $now->subDays(3), 1),
            $this->transitionEvent('started', $now->subDays(3), 2),
            $this->transitionEvent('awaiting_close', $now, 3),
        ];

        $this->asToken($token)->postJson('/api/v1/sync/events', [
            'device_uuid' => $this->device->device_uuid,
            'events' => $events,
        ])->assertOk();

        $seconds = $this->visit->refresh()->on_site_seconds;

        // Capped at one long shift rather than accepting a three-day claim.
        $this->assertLessThanOrEqual(16 * 3600, $seconds);
        $this->assertGreaterThan(0, $seconds);
    }

    public function test_an_online_transition_still_measures_wall_clock_time(): void
    {
        $machine = app(\App\Services\VisitStateMachine::class);

        $this->travelTo(CarbonImmutable::now()->startOfHour());
        $machine->transition($this->visit, Visit::STATE_EN_ROUTE);
        $machine->transition($this->visit->refresh(), Visit::STATE_STARTED);

        $this->travel(45)->minutes();
        $machine->transition($this->visit->refresh(), Visit::STATE_AWAITING_CLOSE);

        $this->assertEqualsWithDelta(45 * 60, $this->visit->refresh()->on_site_seconds, 60);
    }

    private function transitionEvent(string $to, CarbonImmutable $at, int $sequence): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'visit_id' => $this->visit->id,
            'event_type' => 'visit.transition',
            'payload' => ['to' => $to],
            'device_timestamp' => $at->toIso8601String(),
            'monotonic_offset_ms' => $sequence * 1000,
            'sequence' => $sequence,
            'source' => 'offline',
        ];
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

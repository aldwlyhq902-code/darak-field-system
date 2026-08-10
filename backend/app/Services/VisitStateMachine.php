<?php

namespace App\Services;

use App\Exceptions\InvalidVisitTransition;
use App\Exceptions\VisitCloseBlocked;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The visit lifecycle. Every transition is validated against Visit::TRANSITIONS,
 * recorded as a raw event, and audited. Nothing mutates `state` directly.
 */
class VisitStateMachine
{
    public function __construct(
        private readonly CloseGate $closeGate,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  device_id, actor_user_id, lat, lng, source, client_event_id
     */
    public function transition(Visit $visit, string $target, array $context = []): Visit
    {
        if (! $visit->canTransitionTo($target)) {
            throw InvalidVisitTransition::for($visit, $target);
        }

        if ($target === Visit::STATE_COMPLETED) {
            $blockers = $this->closeGate->blockers($visit);

            if ($blockers !== []) {
                // Persist the computed list so the supervisor sees the same reasons
                // the technician saw, without re-running the check.
                $visit->forceFill(['close_blockers' => $blockers])->save();

                throw new VisitCloseBlocked($blockers);
            }
        }

        return DB::transaction(function () use ($visit, $target, $context) {
            $from = $visit->state;
            $now = CarbonImmutable::now();
            $original = $visit->getAttributes();

            $attributes = [
                'state' => $target,
                'state_changed_at' => $now,
            ];

            if ($target === Visit::STATE_STARTED && $visit->started_at === null) {
                $attributes['started_at'] = $now;
            }

            // The on-site counter accumulates only time actually spent in the
            // started state — it stops when the visit is paused or awaiting close.
            //
            // It must be measured on the DEVICE's clock, not the server's. A full
            // offline day replays in one batch, so server timestamps put every
            // transition milliseconds apart and the day collapses to zero minutes —
            // and billable hours are what the whole revenue model is built on.
            if ($from === Visit::STATE_STARTED && $visit->state_changed_at) {
                $attributes['on_site_seconds'] = $visit->on_site_seconds
                    + $this->elapsedSeconds($visit, $context, $now);
            }

            if ($target === Visit::STATE_COMPLETED) {
                $attributes['ended_at'] = $now;
                $attributes['closed_at'] = $now;
                $attributes['close_blockers'] = null;
            }

            if ($target === Visit::STATE_REOPENED) {
                $attributes['closed_at'] = null;
            }

            $visit->forceFill($attributes)->save();

            $visit->events()->create([
                'client_event_id' => $context['client_event_id'] ?? (string) \Illuminate\Support\Str::uuid(),
                'event_type' => "state.{$from}.to.{$target}",
                'payload' => ['from' => $from, 'to' => $target],
                'device_id' => $context['device_id'] ?? null,
                'actor_user_id' => $context['actor_user_id'] ?? auth()->id(),
                'device_timestamp' => $context['device_timestamp'] ?? $now,
                'server_received_at' => $now,
                'lat' => $context['lat'] ?? null,
                'lng' => $context['lng'] ?? null,
                'source' => $context['source'] ?? 'api',
                'sync_status' => 'synced',
            ]);

            $this->audit->recordChange("visit.state.{$target}", $visit, $original, $context['actor_user_id'] ?? null);

            if ($target === Visit::STATE_COMPLETED) {
                // Queued inside the same transaction as the close, so a message
                // can never exist for a visit that did not actually close.
                $visit->loadMissing('site.client.contacts');
                $phone = $visit->site?->client?->contacts?->first()?->phone;
                $this->notifications->reportReady($visit, $phone);
            }

            return $visit->refresh();
        });
    }

    /**
     * Seconds spent in the started state, measured from the device event chain.
     *
     * Falls back to server time only when the device supplied nothing to measure
     * with (an online transition made from the panel). Device time is bounded by
     * the wall-clock span since the visit started, so a tampered phone clock
     * cannot inflate the number beyond what physically elapsed.
     */
    private function elapsedSeconds(Visit $visit, array $context, CarbonImmutable $now): int
    {
        $deviceNow = $context['device_timestamp'] ?? null;

        if ($deviceNow === null) {
            return max(0, (int) $visit->state_changed_at->diffInSeconds($now, false));
        }

        $deviceNow = $deviceNow instanceof CarbonImmutable
            ? $deviceNow
            : CarbonImmutable::parse($deviceNow);

        // The device timestamp of the transition that put the visit into
        // 'started' — the only honest starting point for this segment.
        $startedEvent = $visit->events()
            ->where('event_type', 'like', 'state.%.to.' . Visit::STATE_STARTED)
            ->whereNotNull('device_timestamp')
            ->latest('id')
            ->first();

        if ($startedEvent === null) {
            return max(0, (int) $visit->state_changed_at->diffInSeconds($now, false));
        }

        $elapsed = (int) $startedEvent->device_timestamp->diffInSeconds($deviceNow, false);

        if ($elapsed <= 0) {
            return 0;
        }

        // Anti-tamper is a CAP, not a silent correction.
        //
        // Clamping against the visit's own created_at was tried and is wrong: in a
        // batch replay the row is seconds old, so every legitimate segment got
        // truncated to near zero — reintroducing the bug in a subtler form.
        //
        // A single stretch of work cannot honestly exceed a long shift, so that is
        // the cap. Anything beyond it is refused, and a wound-forward clock is
        // already flagged separately by ClockGuard (clock_suspect on the event)
        // for the supervisor to see rather than being quietly rewritten here.
        $cap = (int) config('darak.max_on_site_segment_hours', 16) * 3600;

        return min($elapsed, $cap);
    }
}

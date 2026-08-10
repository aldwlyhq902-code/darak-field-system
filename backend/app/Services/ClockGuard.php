<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;

/**
 * Device time is recorded but never trusted on its own.
 *
 * Review finding (critical): a technician can change the phone clock before firing
 * events, and checking divergence only after sync does not recover the truth. Nor
 * can we simply compare device_timestamp to server_received_at — an event created
 * legitimately three hours ago while offline WILL look three hours old.
 *
 * So the device reports three things, and we cross-check them:
 *   last_trusted_server_time — server time observed at the previous successful sync
 *   monotonic_offset_ms      — elapsed time since then from a monotonic source that
 *                              does not move when the user edits the wall clock
 *   device_timestamp         — the wall clock at capture
 *
 * expected = last_trusted_server_time + monotonic_offset
 * A large gap between expected and device_timestamp means the wall clock moved.
 */
class ClockGuard
{
    public const SUSPECT_THRESHOLD_SECONDS = 120;

    /**
     * @return array{divergence: ?int, suspect: bool}
     */
    public function evaluate(
        ?CarbonImmutable $deviceTimestamp,
        ?CarbonImmutable $lastTrustedServerTime,
        ?int $monotonicOffsetMs,
    ): array {
        if ($deviceTimestamp === null || $lastTrustedServerTime === null || $monotonicOffsetMs === null) {
            // Not enough information to judge. Record nothing rather than guess.
            return ['divergence' => null, 'suspect' => false];
        }

        $expected = $lastTrustedServerTime->addMilliseconds($monotonicOffsetMs);
        $divergence = (int) $expected->diffInSeconds($deviceTimestamp, false);

        return [
            'divergence' => $divergence,
            'suspect' => abs($divergence) > self::SUSPECT_THRESHOLD_SECONDS,
        ];
    }

    public function rememberSkew(Device $device, ?int $divergenceSeconds): void
    {
        $device->forceFill([
            'clock_skew_seconds' => $divergenceSeconds,
            'last_sync_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Ordering for replayed events. Per-device sequence is authoritative because it
     * survives a wall-clock change; timestamps are only a tiebreaker.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public function orderForReplay(array $events): array
    {
        usort($events, function (array $a, array $b) {
            $seqA = $a['sequence'] ?? null;
            $seqB = $b['sequence'] ?? null;

            if ($seqA !== null && $seqB !== null && $seqA !== $seqB) {
                return $seqA <=> $seqB;
            }

            return strcmp((string) ($a['device_timestamp'] ?? ''), (string) ($b['device_timestamp'] ?? ''));
        });

        return $events;
    }
}

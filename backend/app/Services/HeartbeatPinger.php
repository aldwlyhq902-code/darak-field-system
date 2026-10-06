<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dead-man's-switch pings (healthchecks.io, UptimeRobot heartbeat, …).
 *
 * The external service alerts when pings STOP, which is the only way to learn
 * that the server, cron or scheduler died: a dead machine cannot report its own
 * death. A failed ping is logged and swallowed — monitoring must never take the
 * schedule down with it. The URL is a secret and is never logged.
 */
class HeartbeatPinger
{
    public function ping(?string $url, string $label): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->get(trim($url));

            if ($response->successful()) {
                return true;
            }

            Log::warning("Heartbeat ping [{$label}] returned HTTP {$response->status()}.");
        } catch (Throwable $exception) {
            Log::warning("Heartbeat ping [{$label}] failed: ".get_debug_type($exception));
        }

        return false;
    }
}

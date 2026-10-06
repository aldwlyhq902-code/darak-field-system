<?php

namespace App\Console\Commands;

use App\Services\HeartbeatPinger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HeartbeatCommand extends Command
{
    public const CACHE_KEY = 'darak:monitor:scheduler-heartbeat';

    private const PING_THROTTLE_KEY = 'darak:monitor:scheduler-heartbeat-pinged';

    protected $signature = 'darak:heartbeat';

    protected $description = 'Record that Laravel scheduler is alive and ping the external heartbeat every five minutes.';

    public function handle(HeartbeatPinger $pinger): int
    {
        Cache::put(self::CACHE_KEY, now()->timestamp, now()->addMinutes(15));
        $this->info('Scheduler heartbeat recorded.');

        $url = config('darak.heartbeat_url');

        if (! is_string($url) || trim($url) === '') {
            return self::SUCCESS;
        }

        // The command runs every minute; the external monitor needs one ping per
        // five. Cache::add is atomic, so overlapping runs cannot double-ping.
        try {
            $due = Cache::add(self::PING_THROTTLE_KEY, now()->timestamp, now()->addSeconds(290));
        } catch (Throwable) {
            $due = true;
        }

        if ($due && $pinger->ping($url, 'scheduler')) {
            $this->line('External heartbeat pinged.');
        }

        // A failed ping is logged by the pinger; it must never fail the schedule.
        return self::SUCCESS;
    }
}

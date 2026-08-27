<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class HeartbeatCommand extends Command
{
    protected $signature = 'darak:heartbeat';

    protected $description = 'Record that Laravel scheduler is alive.';

    public function handle(): int
    {
        Cache::put('darak:monitor:scheduler-heartbeat', now()->timestamp, now()->addMinutes(15));
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}

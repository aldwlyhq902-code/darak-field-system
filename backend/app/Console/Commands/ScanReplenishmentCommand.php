<?php

namespace App\Console\Commands;

use App\Services\ReplenishmentService;
use Illuminate\Console\Command;

class ScanReplenishmentCommand extends Command
{
    protected $signature = 'darak:replenishment-scan';

    protected $description = 'Create replenishment requests for warehouse stock below reorder level';

    public function handle(ReplenishmentService $service): int
    {
        $this->info($service->scan()->count().' replenishment request(s) created.');

        return self::SUCCESS;
    }
}

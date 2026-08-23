<?php

namespace App\Console\Commands;

use App\Services\MaintenancePlanService;
use Illuminate\Console\Command;

class GenerateMaintenanceVisitsCommand extends Command
{
    protected $signature = 'darak:maintenance-generate';

    protected $description = 'Generate visits for due preventive-maintenance plans.';

    public function handle(MaintenancePlanService $service): int
    {
        $this->info('Generated '.$service->generateDuePlans().' preventive visit(s).');

        return self::SUCCESS;
    }
}

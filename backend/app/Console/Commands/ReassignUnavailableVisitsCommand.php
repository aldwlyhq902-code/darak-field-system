<?php

namespace App\Console\Commands;

use App\Services\AutomaticReassignmentService;
use Illuminate\Console\Command;

class ReassignUnavailableVisitsCommand extends Command
{
    protected $signature = 'darak:reassign-unavailable';

    protected $description = 'Reassign scheduled visits when a technician is absent or a vehicle is unavailable';

    public function handle(AutomaticReassignmentService $service): int
    {
        $this->info($service->run().' visit(s) reassigned.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\ComplianceAlertService;
use Illuminate\Console\Command;

class ComplianceAlertsCommand extends Command
{
    protected $signature = 'darak:compliance-alerts';

    protected $description = 'Queue employee-document, vehicle-document, and fleet-maintenance alerts.';

    public function handle(ComplianceAlertService $alerts): int
    {
        $this->info('Queued '.$alerts->scan().' compliance alert(s).');

        return self::SUCCESS;
    }
}

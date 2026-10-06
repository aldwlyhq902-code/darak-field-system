<?php

namespace App\Console\Commands;

use App\Services\CommissionService;
use Illuminate\Console\Command;

class GenerateCommissionsCommand extends Command
{
    protected $signature = 'darak:commissions-generate';

    protected $description = 'Accrue commission entries from active policies, reverse voided ones and mark matured ones earned';

    public function handle(CommissionService $service): int
    {
        // Order matters: a refund/cancellation inside the clawback window must be
        // reversed BEFORE the window check promotes the entry to earned.
        $created = $service->generate();
        $reversed = $service->reverseVoided();
        $earned = $service->promoteEarned();

        $this->info("{$created} commission entry(s) accrued, {$reversed} reversed/adjusted, {$earned} earned.");

        return self::SUCCESS;
    }
}

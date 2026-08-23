<?php

namespace App\Console\Commands;

use App\Services\CommissionService;
use Illuminate\Console\Command;

class GenerateCommissionsCommand extends Command
{
    protected $signature = 'darak:commissions-generate';

    protected $description = 'Generate idempotent commission entries from active policies';

    public function handle(CommissionService $service): int
    {
        $this->info($service->generate().' commission entry(s) created.');

        return self::SUCCESS;
    }
}

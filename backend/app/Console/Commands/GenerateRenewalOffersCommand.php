<?php

namespace App\Console\Commands;

use App\Services\ContractRenewalService;
use Illuminate\Console\Command;

class GenerateRenewalOffersCommand extends Command
{
    protected $signature = 'darak:renewal-offers {--days=45}';

    protected $description = 'Generate and send renewal quotations for contracts approaching expiry.';

    public function handle(ContractRenewalService $service): int
    {
        $this->info('Generated '.$service->generateOffers((int) $this->option('days')).' renewal offer(s).');

        return self::SUCCESS;
    }
}

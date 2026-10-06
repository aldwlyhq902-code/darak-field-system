<?php

namespace App\Console\Commands;

use App\Models\FaultPredictionModel;
use App\Services\FaultPredictionTrainer;
use Illuminate\Console\Command;

class TrainFaultPredictionCommand extends Command
{
    protected $signature = 'darak:fault-model-train';

    protected $description = 'Train and validate each company\'s 90-day asset fault recurrence model';

    public function handle(FaultPredictionTrainer $trainer): int
    {
        // One model per company; a company without enough history is skipped,
        // never padded with another company's.
        foreach ($trainer->trainAll() as $companyId => $result) {
            if ($result instanceof FaultPredictionModel) {
                $this->info("Company {$companyId}, model {$result->id}: accuracy {$result->accuracy}, precision {$result->precision}, recall {$result->recall}");
            } else {
                $this->warn("Company {$companyId}: {$result}");
            }
        }

        return self::SUCCESS;
    }
}

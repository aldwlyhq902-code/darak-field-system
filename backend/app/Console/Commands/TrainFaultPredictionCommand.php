<?php

namespace App\Console\Commands;

use App\Services\FaultPredictionTrainer;
use Illuminate\Console\Command;
use RuntimeException;

class TrainFaultPredictionCommand extends Command
{
    protected $signature = 'darak:fault-model-train';

    protected $description = 'Train and validate the 90-day asset fault recurrence model';

    public function handle(FaultPredictionTrainer $trainer): int
    {
        try {
            $model = $trainer->train();
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }
        $this->info("Model {$model->id}: accuracy {$model->accuracy}, precision {$model->precision}, recall {$model->recall}");

        return self::SUCCESS;
    }
}

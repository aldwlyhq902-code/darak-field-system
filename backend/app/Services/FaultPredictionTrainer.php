<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetFaultPrediction;
use App\Models\FaultPredictionModel;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FaultPredictionTrainer
{
    private const FEATURES = 4;

    public function train(): FaultPredictionModel
    {
        $rows = $this->samples();
        $minimum = (int) config('darak.prediction.minimum_samples', 500);
        if ($rows->count() < $minimum) {
            throw new RuntimeException("لا يمكن تدريب النموذج قبل توفر {$minimum} عينة زمنية؛ المتوفر {$rows->count()}.");
        }
        $split = max(1, (int) floor($rows->count() * .8));
        $training = $rows->take($split)->values();
        $validation = $rows->slice($split)->values();
        $weights = array_fill(0, self::FEATURES + 1, 0.0);
        $rate = .12;
        for ($epoch = 0; $epoch < 700; $epoch++) {
            $gradient = array_fill(0, self::FEATURES + 1, 0.0);
            foreach ($training as $row) {
                $prediction = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $row['features']));
                $error = $prediction - $row['label'];
                $gradient[0] += $error;
                foreach ($row['features'] as $index => $feature) {
                    $gradient[$index + 1] += $error * $feature;
                }
            }
            foreach ($weights as $index => $weight) {
                $weights[$index] -= $rate * $gradient[$index] / max(1, $training->count());
            }
        }
        $metrics = $this->metrics($validation, $weights);

        return DB::transaction(function () use ($weights, $training, $validation, $metrics): FaultPredictionModel {
            FaultPredictionModel::where('is_active', true)->update(['is_active' => false]);
            $model = FaultPredictionModel::create([
                'coefficients' => $weights, 'feature_scaling' => ['age_years' => 20, 'faults_90d' => 5, 'faults_365d' => 20, 'days_since_fault' => 180],
                'training_samples' => $training->count(), 'validation_samples' => $validation->count(),
                'accuracy' => $metrics['accuracy'], 'precision' => $metrics['precision'], 'recall' => $metrics['recall'],
                'trained_at' => now(), 'is_active' => true,
            ]);
            $this->generatePredictions($model);

            return $model;
        });
    }

    /** @return Collection<int, array{features:array<int,float>,label:int}> */
    private function samples(): Collection
    {
        $visits = Visit::with('workOrder.asset')->where('state', Visit::STATE_COMPLETED)
            ->whereHas('workOrder', fn ($query) => $query->whereNotNull('asset_id')->whereNotNull('fault_code'))
            ->orderBy('scheduled_start')->get()->groupBy(fn ($visit) => $visit->workOrder->asset_id);
        $rows = collect();
        foreach ($visits as $assetVisits) {
            $assetVisits = $assetVisits->values();
            foreach ($assetVisits as $index => $visit) {
                $at = $visit->scheduled_start ?? $visit->closed_at;
                if (! $at) {
                    continue;
                }
                $previous = $assetVisits->take($index)->filter(fn ($row) => ($row->scheduled_start ?? $row->closed_at)?->lte($at));
                $next = $assetVisits->get($index + 1);
                $nextAt = $next?->scheduled_start ?? $next?->closed_at;
                $rows->push([
                    'features' => $this->features($visit->workOrder->asset, $previous, $at),
                    'label' => $nextAt && $at->diffInDays($nextAt, false) <= 90 ? 1 : 0,
                ]);
            }
        }

        return $rows;
    }

    private function features(Asset $asset, Collection $previous, CarbonInterface $at): array
    {
        $age = $asset->installed_on ? min(40, $asset->installed_on->diffInYears($at)) : 5;
        $faults90 = $previous->filter(fn ($visit) => ($visit->scheduled_start ?? $visit->closed_at)?->gte($at->copy()->subDays(90)))->count();
        $faults365 = $previous->filter(fn ($visit) => ($visit->scheduled_start ?? $visit->closed_at)?->gte($at->copy()->subDays(365)))->count();
        $last = $previous->last();
        $lastAt = $last?->scheduled_start ?? $last?->closed_at;
        $gap = $lastAt ? min(365, $lastAt->diffInDays($at)) : 180;

        return [min(2, $age / 20), min(2, $faults90 / 5), min(2, $faults365 / 20), min(2, $gap / 180)];
    }

    private function generatePredictions(FaultPredictionModel $model): void
    {
        $weights = $model->coefficients;
        Asset::with('site')->where('status', '!=', 'retired')->chunkById(100, function ($assets) use ($model, $weights): void {
            $histories = Visit::with('workOrder')
                ->whereHas('workOrder', fn ($query) => $query
                    ->whereIn('asset_id', $assets->modelKeys())
                    ->whereNotNull('fault_code'))
                ->where('state', Visit::STATE_COMPLETED)
                ->orderBy('scheduled_start')
                ->get()
                ->groupBy(fn (Visit $visit) => $visit->workOrder->asset_id);
            $generatedAt = now();
            $horizon = today()->addDays(90)->toDateString();
            $rows = [];

            foreach ($assets as $asset) {
                $history = $histories->get($asset->id, collect());
                $features = $this->features($asset, $history, now());
                $risk = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $features));
                $rows[] = [
                    'fault_prediction_model_id' => $model->id,
                    'asset_id' => $asset->id,
                    'risk_score' => round($risk, 4),
                    'feature_snapshot' => json_encode($features, JSON_THROW_ON_ERROR),
                    'horizon_ends_on' => $horizon,
                    'generated_at' => $generatedAt,
                    'created_at' => $generatedAt,
                    'updated_at' => $generatedAt,
                ];
            }

            AssetFaultPrediction::query()->upsert(
                $rows,
                ['fault_prediction_model_id', 'asset_id'],
                ['risk_score', 'feature_snapshot', 'horizon_ends_on', 'generated_at', 'updated_at'],
            );
        });
    }

    private function metrics(Collection $rows, array $weights): array
    {
        $tp = $tn = $fp = $fn = 0;
        foreach ($rows as $row) {
            $predicted = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $row['features'])) >= .5 ? 1 : 0;
            match ([$predicted, $row['label']]) {
                [1, 1] => $tp++, [0, 0] => $tn++, [1, 0] => $fp++, default => $fn++
            };
        }

        return [
            'accuracy' => round(($tp + $tn) / max(1, $rows->count()), 4),
            'precision' => round($tp / max(1, $tp + $fp), 4),
            'recall' => round($tp / max(1, $tp + $fn), 4),
        ];
    }

    private function dot(array $left, array $right): float
    {
        return array_sum(array_map(fn ($a, $b) => $a * $b, $left, $right));
    }

    private function sigmoid(float $value): float
    {
        return 1 / (1 + exp(-max(-30, min(30, $value))));
    }
}

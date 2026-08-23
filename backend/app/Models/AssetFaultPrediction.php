<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetFaultPrediction extends Model
{
    protected $fillable = ['fault_prediction_model_id', 'asset_id', 'risk_score', 'feature_snapshot', 'horizon_ends_on', 'generated_at'];

    protected function casts(): array
    {
        return ['risk_score' => 'decimal:4', 'feature_snapshot' => 'array', 'horizon_ends_on' => 'date', 'generated_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(FaultPredictionModel::class, 'fault_prediction_model_id');
    }
}

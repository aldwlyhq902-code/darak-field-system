<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FaultPredictionModel extends Model
{
    protected $fillable = ['model_type', 'coefficients', 'feature_scaling', 'training_samples', 'validation_samples', 'accuracy', 'precision', 'recall', 'trained_at', 'is_active'];

    protected function casts(): array
    {
        return ['coefficients' => 'array', 'feature_scaling' => 'array', 'trained_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(AssetFaultPrediction::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One company's failure-prediction model: trained on, and scoring, that company's assets only. */
class FaultPredictionModel extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['operating_company_id', 'model_type', 'coefficients', 'feature_scaling', 'training_samples', 'validation_samples', 'accuracy', 'precision', 'recall', 'trained_at', 'is_active'];

    protected function casts(): array
    {
        return ['coefficients' => 'array', 'feature_scaling' => 'array', 'trained_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(AssetFaultPrediction::class);
    }
}

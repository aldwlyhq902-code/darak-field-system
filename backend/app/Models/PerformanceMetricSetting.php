<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceMetricSetting extends Model
{
    protected $fillable = [
        'category', 'metric_key', 'label_ar', 'weight', 'target',
        'direction', 'minimum_sample', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2', 'target' => 'decimal:2',
            'minimum_sample' => 'integer', 'is_active' => 'boolean',
        ];
    }
}

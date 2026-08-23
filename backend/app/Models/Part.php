<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku', 'name', 'unit', 'purchase_cost', 'sale_price', 'member_price',
        'qr_code', 'heat_sensitive', 'max_storage_temp_c', 'reorder_level', 'is_active',
        'critical_tracking', 'default_warranty_months',
    ];

    protected function casts(): array
    {
        return [
            'purchase_cost' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'member_price' => 'decimal:2',
            'max_storage_temp_c' => 'decimal:2',
            'reorder_level' => 'decimal:2',
            'heat_sensitive' => 'boolean',
            'is_active' => 'boolean',
            'critical_tracking' => 'boolean',
        ];
    }

    public function stockMoves(): HasMany
    {
        return $this->hasMany(StockMove::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_parts')->withPivot(['supplier_sku', 'last_price', 'lead_time_days', 'is_preferred'])->withTimestamps();
    }
}

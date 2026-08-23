<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $fillable = ['name', 'applies_to_role', 'basis', 'rate', 'fixed_amount', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'fixed_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }
}

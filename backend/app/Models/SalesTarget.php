<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    protected $fillable = ['user_id', 'month', 'calls_target', 'meetings_target', 'proposals_target', 'won_value_target', 'collections_target', 'set_by'];

    protected function casts(): array
    {
        return ['month' => 'date', 'won_value_target' => 'decimal:2', 'collections_target' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

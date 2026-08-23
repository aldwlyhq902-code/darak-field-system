<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLine extends Model
{
    protected $fillable = ['stocktake_session_id', 'part_id', 'expected_qty', 'counted_qty', 'variance_qty', 'scan_code'];

    protected function casts(): array
    {
        return ['expected_qty' => 'decimal:3', 'counted_qty' => 'decimal:3', 'variance_qty' => 'decimal:3'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class, 'stocktake_session_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One owner-recorded payout to one beneficiary. net = gross − offsets, never negative. */
class CommissionPayout extends Model
{
    protected $fillable = [
        'user_id', 'reference', 'paid_on', 'gross_amount', 'offset_amount', 'net_amount',
        'offsets', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date', 'gross_amount' => 'decimal:2', 'offset_amount' => 'decimal:2',
            'net_amount' => 'decimal:2', 'offsets' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'contract_no', 'package_code', 'price_amount', 'vat_rate',
        'billing_cycle', 'starts_on', 'ends_on',
        'service_window_start', 'service_window_end', 'sla_minutes',
        'included_assets_cap', 'extra_asset_price', 'included_visits_per_cycle',
        'included_hours_per_cycle', 'exclusions', 'founding_inspection_fee',
        'founding_inspection_credited', 'is_trial', 'decision_due_on', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decision_due_on' => 'date',
            'price_amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'extra_asset_price' => 'decimal:2',
            'founding_inspection_fee' => 'decimal:2',
            'included_hours_per_cycle' => 'decimal:2',
            'exclusions' => 'array',
            'is_trial' => 'boolean',
            'founding_inspection_credited' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'contract_site')->withTimestamps();
    }

    /** Stored amounts are pre-VAT; this is the gross figure shown to the client. */
    public function priceInclVat(): float
    {
        return round((float) $this->price_amount * (1 + (float) $this->vat_rate), 2);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'wo_number', 'client_id', 'site_id', 'contract_id', 'asset_id',
        'type', 'priority', 'title', 'description', 'reported_at',
        'sla_due_at', 'sla_minutes_budget', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'sla_due_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function subcontractorOrders(): HasMany
    {
        return $this->hasMany(SubcontractorOrder::class);
    }
}

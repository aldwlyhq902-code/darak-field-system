<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id', 'type', 'name', 'manufacturer', 'model', 'serial_number',
        'location_in_site', 'installed_on', 'warranty_provider', 'warranty_until',
        'status', 'qr_code', 'client_generated_uuid', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'warranty_until' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function checklistInstances(): HasMany
    {
        return $this->hasMany(ChecklistInstance::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /** Blocking warning before a part is billed on an asset still under warranty. */
    public function isUnderWarranty(): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->isFuture();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMove extends Model
{
    use HasFactory;

    public const RECEIPT = 'RECEIPT';
    public const VEHICLE_LOAD = 'VEHICLE_LOAD';
    public const VISIT_ISSUE = 'VISIT_ISSUE';
    public const VISIT_RETURN = 'VISIT_RETURN';
    public const ADJUSTMENT = 'ADJUSTMENT';

    public const TYPES = [
        self::RECEIPT, self::VEHICLE_LOAD, self::VISIT_ISSUE,
        self::VISIT_RETURN, self::ADJUSTMENT,
    ];

    protected $fillable = [
        'move_type', 'part_id', 'qty', 'from_location_id', 'to_location_id',
        'visit_id', 'user_id', 'device_id', 'idempotency_key', 'reversal_of_id',
        'unit_cost', 'device_timestamp', 'server_received_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'device_timestamp' => 'datetime',
            'server_received_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}

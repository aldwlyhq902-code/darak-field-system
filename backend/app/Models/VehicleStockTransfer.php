<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleStockTransfer extends Model
{
    use HasFactory;
    use ScopedToOperatingBranch;

    protected $fillable = ['transfer_no', 'part_id', 'qty', 'from_location_id', 'to_location_id', 'status', 'requested_by', 'released_by', 'released_at', 'accepted_by', 'accepted_at', 'note'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'released_at' => 'datetime', 'accepted_at' => 'datetime'];
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
}

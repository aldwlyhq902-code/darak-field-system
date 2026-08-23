<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplenishmentRequest extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['request_no', 'part_id', 'stock_location_id', 'suggested_supplier_id', 'current_qty', 'target_qty', 'suggested_qty', 'status', 'purchase_order_id'];

    protected function casts(): array
    {
        return ['current_qty' => 'decimal:3', 'target_qty' => 'decimal:3', 'suggested_qty' => 'decimal:3'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'suggested_supplier_id');
    }
}

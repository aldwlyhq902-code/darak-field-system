<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLot extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['part_id', 'supplier_id', 'purchase_order_item_id', 'stock_location_id', 'lot_number', 'serial_number', 'manufactured_on', 'warranty_until', 'qty_received', 'qty_remaining', 'unit_cost'];

    protected function casts(): array
    {
        return ['manufactured_on' => 'date', 'warranty_until' => 'date', 'qty_received' => 'decimal:3', 'qty_remaining' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}

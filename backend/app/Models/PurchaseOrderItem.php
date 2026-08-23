<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = ['purchase_order_id', 'part_id', 'qty_ordered', 'qty_received', 'unit_cost'];

    protected function casts(): array
    {
        return ['qty_ordered' => 'decimal:3', 'qty_received' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function remaining(): float
    {
        return max(0, round((float) $this->qty_ordered - (float) $this->qty_received, 3));
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = ['po_number', 'supplier_id', 'destination_location_id', 'ordered_on', 'expected_on', 'status', 'subtotal', 'vat_amount', 'total_amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'expected_on' => 'date', 'subtotal' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'destination_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}

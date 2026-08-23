<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierQuotation extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['request_for_quotation_id', 'supplier_id', 'supplier_reference', 'valid_until', 'lead_time_days', 'subtotal', 'vat_amount', 'total_amount', 'status', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'subtotal' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function requestForQuotation(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierQuotationItem::class);
    }
}

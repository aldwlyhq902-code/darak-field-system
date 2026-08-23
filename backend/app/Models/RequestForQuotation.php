<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestForQuotation extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['rfq_no', 'destination_location_id', 'response_due_on', 'status', 'note', 'created_by', 'sent_at', 'awarded_supplier_quotation_id', 'purchase_order_id'];

    protected function casts(): array
    {
        return ['response_due_on' => 'date', 'sent_at' => 'datetime'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'destination_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestForQuotationItem::class);
    }

    public function supplierQuotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function awardedQuotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'awarded_supplier_quotation_id');
    }
}

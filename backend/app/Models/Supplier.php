<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier in one company's directory; its negotiated part prices
 * (supplier_parts) belong to the same company. A company-less row is a legacy
 * platform default only platform admins see.
 */
class Supplier extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = ['operating_company_id', 'name', 'vat_number', 'phone', 'email', 'lead_time_days', 'is_active', 'notes'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(OperatingCompany::class, 'operating_company_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class, 'supplier_parts')->withPivot(['supplier_sku', 'last_price', 'lead_time_days', 'is_preferred'])->withTimestamps();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }
}

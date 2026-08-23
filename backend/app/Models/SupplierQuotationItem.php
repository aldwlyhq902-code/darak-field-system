<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuotationItem extends Model
{
    protected $fillable = ['supplier_quotation_id', 'part_id', 'qty', 'unit_cost'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}

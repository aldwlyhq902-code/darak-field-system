<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestForQuotationItem extends Model
{
    protected $fillable = ['request_for_quotation_id', 'part_id', 'qty', 'specification'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}

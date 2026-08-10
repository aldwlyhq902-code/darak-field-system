<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubcontractorOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'subcontractor_id', 'work_order_id', 'visit_id', 'order_no',
        'purchase_cost', 'sale_price', 'status', 'scheduled_for',
        'delivered_at', 'documents', 'note',
    ];

    protected function casts(): array
    {
        return [
            'purchase_cost' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'scheduled_for' => 'datetime',
            'delivered_at' => 'datetime',
            'documents' => 'array',
        ];
    }

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Shown before the assignment is confirmed, never after the fact. */
    public function margin(): float
    {
        return round((float) $this->sale_price - (float) $this->purchase_cost, 2);
    }

    public function marginPercent(): ?float
    {
        $sale = (float) $this->sale_price;

        return $sale > 0 ? round($this->margin() / $sale * 100, 1) : null;
    }
}

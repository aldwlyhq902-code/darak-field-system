<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubcontractorOrder extends Model
{
    use HasFactory, ScopedToOperatingBranch;

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
    public function margin(): string
    {
        return Money::sub($this->sale_price, $this->purchase_cost);
    }

    public function marginPercent(): ?float
    {
        return Money::ratioPercent($this->margin(), $this->sale_price);
    }
}

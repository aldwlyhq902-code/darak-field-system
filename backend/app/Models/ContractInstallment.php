<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractInstallment extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = [
        'contract_id', 'installment_no', 'due_on', 'amount', 'vat_amount',
        'total_amount', 'paid_amount', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date', 'amount' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'paid_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Exact outstanding amount as a money string ("0.00" when settled). */
    public function remaining(): string
    {
        return Money::max(Money::sub($this->total_amount, $this->paid_amount), 0);
    }
}

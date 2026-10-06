<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = [
        'receipt_no', 'contract_installment_id', 'client_id', 'amount', 'paid_on',
        'method', 'reference', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'refunded_at' => 'datetime'];
    }

    /** A refunded receipt stays on record; it no longer counts as collected money. */
    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(ContractInstallment::class, 'contract_installment_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

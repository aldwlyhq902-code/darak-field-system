<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionEntry extends Model
{
    protected $fillable = ['commission_rule_id', 'user_id', 'contract_id', 'visit_id', 'payment_id', 'basis_amount', 'commission_amount', 'status'];

    protected function casts(): array
    {
        return ['basis_amount' => 'decimal:2', 'commission_amount' => 'decimal:2'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialApproval extends Model
{
    protected $fillable = ['public_reference', 'action_type', 'subject_type', 'subject_id', 'amount', 'reason', 'status', 'requested_by', 'first_approved_by', 'second_approved_by', 'first_approved_at', 'second_approved_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'first_approved_at' => 'datetime', 'second_approved_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }
}

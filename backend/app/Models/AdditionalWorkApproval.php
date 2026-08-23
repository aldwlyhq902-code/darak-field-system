<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalWorkApproval extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = ['public_reference', 'visit_id', 'client_id', 'title', 'description', 'items', 'amount', 'vat_amount', 'total_amount', 'status', 'sent_at', 'responded_at', 'responded_by_portal_user_id', 'response_note', 'created_by', 'requested_from'];

    protected function casts(): array
    {
        return ['items' => 'array', 'amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

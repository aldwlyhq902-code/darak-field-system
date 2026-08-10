<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalDocument extends Model
{
    use HasFactory;

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_DEBIT_NOTE = 'debit_note';

    protected $fillable = [
        'doc_type', 'provider', 'external_id', 'external_number', 'parent_document_id',
        'client_id', 'contract_id', 'work_order_id', 'visit_id',
        'amount', 'vat_amount', 'status', 'issued_at',
        'request_payload', 'response_payload', 'last_error', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExternalDocument::class, 'parent_document_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(ExternalDocument::class, 'parent_document_id')
            ->where('doc_type', self::TYPE_CREDIT_NOTE);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}

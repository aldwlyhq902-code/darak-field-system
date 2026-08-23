<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Quotation extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'series_uuid', 'version', 'quote_no', 'client_id', 'title', 'package_code',
        'price_amount', 'vat_rate', 'billing_cycle', 'duration_months', 'starts_on',
        'valid_until', 'service_window_start', 'service_window_end', 'sla_minutes',
        'terms', 'status', 'sent_at', 'accepted_at', 'accepted_by_portal_user_id',
        'converted_contract_id', 'created_by',
        'down_payment_amount', 'custom_installments', 'renews_contract_id',
        'auto_generated', 'superseded_at', 'acceptance_ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2', 'vat_rate' => 'decimal:4',
            'starts_on' => 'date', 'valid_until' => 'date', 'terms' => 'array',
            'sent_at' => 'datetime', 'accepted_at' => 'datetime',
            'down_payment_amount' => 'decimal:2', 'custom_installments' => 'array',
            'auto_generated' => 'boolean', 'superseded_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'quotation_site')->withTimestamps();
    }

    public function convertedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'converted_contract_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(ClientPortalUser::class, 'accepted_by_portal_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function priceInclVat(): float
    {
        return round((float) $this->price_amount * (1 + (float) $this->vat_rate), 2);
    }
}

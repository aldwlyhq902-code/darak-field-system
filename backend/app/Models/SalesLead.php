<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesLead extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['lead_no', 'company_name', 'contact_name', 'phone', 'email', 'stage', 'source', 'campaign_name', 'estimated_value', 'probability_percent', 'expected_close_on', 'next_action_on', 'last_contacted_at', 'owner_user_id', 'operating_branch_id', 'converted_client_id', 'converted_at', 'notes'];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2', 'probability_percent' => 'integer',
            'expected_close_on' => 'date', 'next_action_on' => 'date',
            'last_contacted_at' => 'datetime', 'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $lead): void {
            if ($lead->operating_branch_id === null && $lead->owner_user_id !== null) {
                $lead->operating_branch_id = User::query()->whereKey($lead->owner_user_id)->value('operating_branch_id');
            }
            $lead->operating_branch_id ??= auth('web')->user()?->operating_branch_id;
        });
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SalesActivity::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function operatingBranch(): BelongsTo
    {
        return $this->belongsTo(OperatingBranch::class, 'operating_branch_id');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SalesLeadAttachment::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePlan extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = [
        'client_id', 'contract_id', 'site_id', 'asset_id', 'title',
        'frequency_days', 'duration_minutes', 'preferred_start', 'next_due_on',
        'last_generated_on', 'preferred_user_id', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'next_due_on' => 'date',
            'last_generated_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function preferredTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_user_id');
    }
}

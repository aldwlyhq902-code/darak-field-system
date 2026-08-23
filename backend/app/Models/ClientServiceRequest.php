<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientServiceRequest extends Model
{
    use ScopedToOperatingBranch;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_reference', 'client_id', 'client_portal_user_id', 'site_id',
        'asset_id', 'category', 'description', 'preferred_date',
        'preferred_time_slot', 'status', 'visit_id', 'response_note',
        'responded_by', 'responded_at',
    ];

    protected function casts(): array
    {
        return ['preferred_date' => 'date', 'responded_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(ClientPortalUser::class, 'client_portal_user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}

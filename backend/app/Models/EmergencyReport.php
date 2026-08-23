<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyReport extends Model
{
    use ScopedToOperatingBranch;

    public const STATUS_NEW = 'new';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_reference', 'site_id', 'asset_id', 'client_portal_user_id',
        'work_order_id', 'reporter_name', 'reporter_phone', 'reporter_role',
        'category', 'severity', 'description', 'photo_path', 'photo_mime',
        'photo_sha256', 'status', 'source', 'ip_hash',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(ClientPortalUser::class, 'client_portal_user_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}

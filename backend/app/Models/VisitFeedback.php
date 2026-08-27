<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitFeedback extends Model
{
    use ScopedToOperatingBranch;

    protected $table = 'visit_feedback';

    protected $fillable = [
        'visit_id', 'client_portal_user_id', 'rating', 'resolution_confirmed',
        'comment', 'is_complaint', 'status', 'supervisor_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'resolution_confirmed' => 'boolean',
            'is_complaint' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(ClientPortalUser::class, 'client_portal_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_REJECTED = 'rejected';

    /** Mirrors the column default so an unsaved model reads the same as the row. */
    protected $attributes = ['approval_status' => self::APPROVAL_APPROVED];

    protected $fillable = [
        'user_id', 'device_uuid', 'platform', 'label', 'app_version',
        'last_seen_at', 'last_sync_at', 'clock_skew_seconds',
        'revoked_at', 'revoked_reason', 'integrity',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
            'approved_at' => 'datetime',
            'integrity' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    /** Root or emulator reported by the app at login. */
    public function hasIntegrityWarning(): bool
    {
        return ($this->integrity['rooted'] ?? false) === true || ($this->integrity['emulator'] ?? false) === true;
    }
}

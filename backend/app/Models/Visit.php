<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use HasFactory;

    /** MVP state machine (PRD v1.2 §2). */
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_EN_ROUTE = 'en_route';
    public const STATE_STARTED = 'started';
    public const STATE_PAUSED = 'paused';
    public const STATE_AWAITING_CLOSE = 'awaiting_close';
    public const STATE_COMPLETED = 'completed';
    public const STATE_REOPENED = 'reopened';

    /**
     * Allowed transitions. Anything not listed here returns HTTP 409 with
     * INVALID_VISIT_TRANSITION — the state machine is the contract, not a suggestion.
     */
    public const TRANSITIONS = [
        self::STATE_SCHEDULED => [self::STATE_EN_ROUTE],
        self::STATE_EN_ROUTE => [self::STATE_STARTED, self::STATE_SCHEDULED],
        self::STATE_STARTED => [self::STATE_PAUSED, self::STATE_AWAITING_CLOSE],
        self::STATE_PAUSED => [self::STATE_STARTED, self::STATE_AWAITING_CLOSE],
        self::STATE_AWAITING_CLOSE => [self::STATE_COMPLETED, self::STATE_STARTED],
        self::STATE_COMPLETED => [self::STATE_REOPENED],
        self::STATE_REOPENED => [self::STATE_STARTED],
    ];

    protected $fillable = [
        'work_order_id', 'site_id', 'assigned_user_id', 'subcontractor_id',
        'scheduled_start', 'scheduled_end', 'state', 'state_changed_at',
        'started_at', 'ended_at', 'on_site_seconds',
        'parent_visit_id', 'is_rework', 'rework_reason', 'rework_system_flagged',
        'rework_overridden_by', 'rework_override_note',
        'is_billable', 'close_blockers', 'closed_at', 'required_asset_ids',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'state_changed_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_rework' => 'boolean',
            'rework_system_flagged' => 'boolean',
            'is_billable' => 'boolean',
            'close_blockers' => 'array',
            'required_asset_ids' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function parentVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'parent_visit_id');
    }

    public function reworkVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'parent_visit_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VisitEvent::class);
    }

    public function checklistInstances(): HasMany
    {
        return $this->hasMany(ChecklistInstance::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function stockMoves(): HasMany
    {
        return $this->hasMany(StockMove::class);
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->state] ?? [], true);
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }
}

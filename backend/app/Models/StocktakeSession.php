<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeSession extends Model
{
    use ScopedToOperatingBranch;

    public const STATUS_OPEN = 'open';

    /** Counted on the phone; waiting for a back-office user to approve the variances. */
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'public_reference', 'stock_location_id', 'assigned_user_id', 'status', 'started_at', 'completed_at', 'created_by',
        'submitted_at', 'submitted_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /** Lines the counter actually scanned. The rest were never counted, not counted as zero. */
    public function scannedLines(): HasMany
    {
        return $this->lines()->whereNotNull('scan_code');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }
}

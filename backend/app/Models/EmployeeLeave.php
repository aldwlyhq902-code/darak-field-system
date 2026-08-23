<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['user_id', 'operating_branch_id', 'type', 'starts_on', 'ends_on', 'days', 'status', 'reason', 'response_note', 'requested_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's commission policy. It pays only that company's people for that
 * company's records. A row with no company is a legacy platform default: only
 * platform admins see it and it generates nothing.
 */
class CommissionRule extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['operating_company_id', 'name', 'applies_to_role', 'basis', 'rate', 'fixed_amount', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'fixed_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(OperatingCompany::class, 'operating_company_id');
    }
}

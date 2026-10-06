<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use App\Models\Scopes\OperatingBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A partner in one company's directory. A row with no company is a legacy
 * platform default only platform admins see.
 */
class Subcontractor extends Model
{
    use HasFactory, ScopedToOperatingBranch, SoftDeletes;

    /**
     * A partner with cost history is archived, never erased. Those purchase
     * costs are what separate a real margin from a fictional one on work that
     * has already been invoiced.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (self $partner) {
            // Every order counts, not only those the current user may see.
            if ($partner->orders()->withoutGlobalScope(OperatingBranchScope::class)->exists()) {
                throw new \RuntimeException(
                    'لا يمكن حذف شريك له أوامر سابقة — تكاليفه جزء من هوامش أعمال منفَّذة. أوقفه بدل حذفه.'
                );
            }
        });
    }

    protected $fillable = [
        'operating_company_id', 'name', 'cr_number', 'specialties', 'contact_name', 'phone',
        'issues_official_certificates', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'issues_official_certificates' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(OperatingCompany::class, 'operating_company_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SubcontractorOrder::class);
    }
}

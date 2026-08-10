<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subcontractor extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * A partner with cost history is archived, never erased. Those purchase
     * costs are what separate a real margin from a fictional one on work that
     * has already been invoiced.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (self $partner) {
            if ($partner->orders()->exists()) {
                throw new \RuntimeException(
                    'لا يمكن حذف شريك له أوامر سابقة — تكاليفه جزء من هوامش أعمال منفَّذة. أوقفه بدل حذفه.'
                );
            }
        });
    }

    protected $fillable = [
        'name', 'cr_number', 'specialties', 'contact_name', 'phone',
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

    public function orders(): HasMany
    {
        return $this->hasMany(SubcontractorOrder::class);
    }
}

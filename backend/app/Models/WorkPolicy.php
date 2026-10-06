<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable version of a company's working-hours policy, as supplied and
 * approved by a qualified specialist. Darak holds no legal numbers of its own:
 * every limit lives in `rules` and any rule may be absent.
 */
class WorkPolicy extends Model
{
    /** Supported rule keys (all minutes, all optional). */
    public const RULES = [
        'max_shift_minutes' => 'الحد الأقصى لمدة الوردية الواحدة',
        'max_daily_minutes' => 'الحد الأقصى لدقائق العمل في اليوم',
        'max_weekly_minutes' => 'الحد الأقصى لدقائق العمل في أي 7 أيام متتالية',
        'min_rest_between_shifts_minutes' => 'الحد الأدنى للراحة بين ورديتين',
    ];

    protected $fillable = [
        'operating_company_id', 'version', 'effective_from', 'source', 'approved_by',
        'approved_at', 'rules', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'approved_at' => 'date', 'rules' => 'array', 'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A published version is evidence of what applied at the time; a change
        // is a new version, never an edit.
        static::updating(fn () => throw new LogicException('Work policy versions are immutable; add a new version.'));
        static::deleting(fn () => throw new LogicException('Work policy versions are immutable; add a new version.'));
    }

    /** The version in force for a company on a given date (latest effective_from, then version). */
    public function scopeEffectiveOn(Builder $query, int $companyId, \DateTimeInterface $date): Builder
    {
        return $query->where('operating_company_id', $companyId)
            ->whereDate('effective_from', '<=', $date->format('Y-m-d'))
            ->orderByDesc('effective_from')->orderByDesc('version');
    }

    public function rule(string $key): ?int
    {
        $value = $this->rules[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(OperatingCompany::class, 'operating_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

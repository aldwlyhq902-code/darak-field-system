<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A scoring weight/target. Rows with no company are the platform defaults; a
 * company's own row for the same metric overrides the default for that company
 * only. Always read through effectiveFor(), never the raw table.
 */
class PerformanceMetricSetting extends Model
{
    protected $fillable = [
        'operating_company_id', 'category', 'metric_key', 'label_ar', 'weight', 'target',
        'direction', 'minimum_sample', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2', 'target' => 'decimal:2',
            'minimum_sample' => 'integer', 'is_active' => 'boolean',
        ];
    }

    /**
     * The settings in force for a company (null = the platform defaults), in
     * the defaults' order.
     *
     * @return Collection<int, self>
     */
    public static function effectiveFor(?int $companyId): Collection
    {
        $rows = static::query()
            ->where(fn ($query) => $query->whereNull('operating_company_id')
                ->when($companyId !== null, fn ($own) => $own->orWhere('operating_company_id', $companyId)))
            ->orderBy('category')->orderBy('id')->get();
        $own = $rows->whereNotNull('operating_company_id')->keyBy(fn (self $row) => $row->category.'|'.$row->metric_key);

        return $rows->whereNull('operating_company_id')
            ->map(fn (self $default) => $own->get($default->category.'|'.$default->metric_key, $default))
            ->values();
    }
}

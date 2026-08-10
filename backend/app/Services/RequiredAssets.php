<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Visit;
use Illuminate\Support\Collection;

/**
 * The single answer to "which assets does this visit have to cover".
 *
 * It exists because the question was being answered in two places with two
 * different rules: bootstrap resolved the frozen ids through the site's live
 * assets while CloseGate demanded every id in the snapshot. An asset deleted
 * after scheduling then disappeared from the phone and stayed required on the
 * server — a visit nobody could ever close. Two readers of one fact will drift;
 * one reader cannot.
 *
 * The rules, in one place:
 *  - `null` means the visit predates the snapshot: fall back to the site.
 *  - `[]` means "nothing required", and is honoured as written. Treating it as
 *    a fallback re-introduced the bug for any visit whose snapshot was empty.
 *  - A soft-deleted asset drops out of the requirement. It cannot be inspected,
 *    and the supervisor is the one who removed it — blocking the visit forever
 *    punishes the technician for someone else's edit. The snapshot still records
 *    that it was scheduled.
 */
class RequiredAssets
{
    /** @return Collection<int, Asset> */
    public function for(Visit $visit): Collection
    {
        $required = $visit->required_asset_ids;

        if ($required === null) {
            $visit->loadMissing('site.assets');

            return $visit->site?->assets ?? collect();
        }

        if ($required === []) {
            return collect();
        }

        // withTrashed so a frozen id always resolves to a row, then the deleted
        // ones are dropped explicitly rather than vanishing through a join.
        return Asset::withTrashed()
            ->whereIn('id', $required)
            ->get()
            ->reject(fn (Asset $asset) => $asset->trashed())
            ->values();
    }

    /**
     * Assets that were scheduled but have since been removed from the site.
     * Surfaced so the report can say why a required unit is absent instead of
     * leaving a silent gap.
     *
     * @return Collection<int, Asset>
     */
    public function retiredSince(Visit $visit): Collection
    {
        $required = $visit->required_asset_ids;

        if ($required === null || $required === []) {
            return collect();
        }

        return Asset::withTrashed()
            ->whereIn('id', $required)
            ->get()
            ->filter(fn (Asset $asset) => $asset->trashed())
            ->values();
    }
}

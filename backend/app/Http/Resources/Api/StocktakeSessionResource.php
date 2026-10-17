<?php

namespace App\Http\Resources\Api;

use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stocktake as the counter's phone sees it: blind (contract C5).
 *
 * The raw model went out with every catalogue line, its expected quantity and
 * variance, and the part's purchase and sale prices. A count the counter can
 * see the answer to is no count: they could take N parts and type expected − N.
 * The phone gets what it has counted so far, and nothing it should not know.
 *
 * @mixin StocktakeSession
 */
class StocktakeSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StocktakeSession $session */
        $session = $this->resource;
        $session->loadMissing(['location', 'scannedLines.part']);

        return [
            'id' => $session->id,
            'public_reference' => $session->public_reference,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'submitted_at' => $session->submitted_at?->toIso8601String(),
            'location' => $session->location === null ? null : [
                'id' => $session->location->id,
                'name' => $session->location->name,
            ],
            'counted' => $session->scannedLines->map(fn (StocktakeLine $line) => [
                'part_id' => $line->part_id,
                'sku' => $line->part?->sku,
                'name' => $line->part?->name,
                'unit' => $line->part?->unit,
                'counted_qty' => (float) $line->counted_qty,
            ])->values()->all(),
        ];
    }
}

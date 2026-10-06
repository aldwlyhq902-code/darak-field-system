<?php

namespace App\Http\Resources\Api;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visit list / state-change response. An explicit ALLOWLIST (contract C5):
 * serialising the model used to ship whatever relations happened to be loaded
 * — after a completion that included the client's CR/VAT numbers, credit limit
 * and internal notes, straight to a technician's phone.
 *
 * @mixin Visit
 */
class VisitSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Visit $visit */
        $visit = $this->resource;

        return [
            'id' => $visit->id,
            'state' => $visit->state,
            'state_changed_at' => $visit->state_changed_at?->toIso8601String(),
            'scheduled_start' => $visit->scheduled_start?->toIso8601String(),
            'scheduled_end' => $visit->scheduled_end?->toIso8601String(),
            'on_site_seconds' => $visit->on_site_seconds,
            'is_rework' => (bool) $visit->is_rework,
            'close_blockers' => $visit->close_blockers,
            'work_order' => $visit->relationLoaded('workOrder') && $visit->workOrder ? [
                'id' => $visit->workOrder->id,
                'number' => $visit->workOrder->wo_number,
                'type' => $visit->workOrder->type,
                'priority' => $visit->workOrder->priority,
                'title' => $visit->workOrder->title,
            ] : null,
            'site' => $visit->relationLoaded('site') && $visit->site ? [
                'id' => $visit->site->id,
                'name' => $visit->site->name,
                'client_name' => $visit->site->relationLoaded('client') ? $visit->site->client?->name : null,
            ] : null,
            'technician' => $visit->relationLoaded('technician') && $visit->technician ? [
                'id' => $visit->technician->id,
                'name' => $visit->technician->name,
            ] : null,
        ];
    }
}

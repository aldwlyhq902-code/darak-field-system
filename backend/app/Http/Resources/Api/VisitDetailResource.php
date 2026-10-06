<?php

namespace App\Http\Resources\Api;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One visit with what field work needs (contract C5 allowlist). Deliberately
 * absent: contract and price fields, client CR/VAT/credit/notes, technician
 * cost and permissions, part purchase/sale prices and stock unit costs.
 *
 * @mixin Visit
 */
class VisitDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Visit $visit */
        $visit = $this->resource;
        $workOrder = $visit->workOrder;
        $site = $visit->site;

        return [
            ...(new VisitSummaryResource($visit))->toArray($request),
            'started_at' => $visit->started_at?->toIso8601String(),
            'ended_at' => $visit->ended_at?->toIso8601String(),
            'closed_at' => $visit->closed_at?->toIso8601String(),
            'estimated_arrival_at' => $visit->estimated_arrival_at?->toIso8601String(),
            'work_order' => $workOrder ? [
                'id' => $workOrder->id,
                'number' => $workOrder->wo_number,
                'type' => $workOrder->type,
                'priority' => $workOrder->priority,
                'title' => $workOrder->title,
                'description' => $workOrder->description,
                'status' => $workOrder->status,
                'fault_code' => $workOrder->fault_code,
                'diagnosis_code' => $workOrder->diagnosis_code,
                'resolution_summary' => $workOrder->resolution_summary,
                'sla_due_at' => $workOrder->sla_due_at?->toIso8601String(),
            ] : null,
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'client_name' => $site->client?->name,
                'address' => $site->address,
                'lat' => $site->lat,
                'lng' => $site->lng,
                'geofence_radius_m' => $site->geofence_radius_m,
                'access_notes' => $site->access_notes,
            ] : null,
            'assets' => $site !== null && $site->relationLoaded('assets') ? $site->assets->map(fn ($asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'type' => $asset->type,
                'location' => $asset->location_in_site,
                'qr_code' => $asset->qr_code,
                'under_warranty' => $asset->isUnderWarranty(),
            ])->values()->all() : [],
            'checklist_instances' => $this->listed('checklistInstances', fn ($instance) => [
                'id' => $instance->id,
                'asset_id' => $instance->asset_id,
                'status' => $instance->status,
                'note' => $instance->note,
                'no_parts_used' => (bool) $instance->no_parts_used,
                'completed_at' => $instance->completed_at?->toIso8601String(),
            ]),
            'media_files' => $this->listed('mediaFiles', fn ($media) => [
                'client_media_id' => $media->client_media_id,
                'kind' => $media->kind,
                'upload_state' => $media->upload_state,
                'uploaded_bytes' => (int) $media->uploaded_bytes,
                'total_bytes' => $media->total_bytes === null ? null : (int) $media->total_bytes,
                'captured_at' => $media->captured_at?->toIso8601String(),
            ]),
            'stock_moves' => $this->listed('stockMoves', fn ($move) => [
                'id' => $move->id,
                'move_type' => $move->move_type,
                'part_id' => $move->part_id,
                'part_name' => $move->relationLoaded('part') ? $move->part?->name : null,
                'qty' => (float) $move->qty,
            ]),
            'stock_reservations' => $this->listed('stockReservations', fn ($reservation) => [
                'part_id' => $reservation->part_id,
                'part_name' => $reservation->relationLoaded('part') ? $reservation->part?->name : null,
                'qty' => (float) $reservation->qty,
                'stock_location_id' => $reservation->stock_location_id,
                'status' => $reservation->status,
            ]),
            'additional_work_approvals' => $this->listed('additionalWorkApprovals', fn ($approval) => [
                'id' => $approval->id,
                'title' => $approval->title,
                'total_amount' => (float) $approval->total_amount,
                'status' => $approval->status,
            ]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function listed(string $relation, callable $map): array
    {
        $visit = $this->resource;

        return $visit->relationLoaded($relation) ? $visit->{$relation}->map($map)->values()->all() : [];
    }
}

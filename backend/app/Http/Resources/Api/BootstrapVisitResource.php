<?php

namespace App\Http\Resources\Api;

use App\Models\Visit;
use App\Services\RequiredAssets;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One visit in the offline bootstrap — exactly the keys the phone stores
 * (local_db.dart replaceVisits) and nothing else (contract C5). The contract is
 * loaded only for its service window; its price never leaves the server.
 *
 * @mixin Visit
 */
class BootstrapVisitResource extends JsonResource
{
    public function __construct(Visit $visit, private readonly CarbonImmutable $now)
    {
        parent::__construct($visit);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Visit $visit */
        $visit = $this->resource;
        $contract = $visit->workOrder?->contract;
        $due = $visit->workOrder?->sla_due_at;
        $sla = $due ? app(SlaCalculator::class)->forVisit($visit, $this->now) : null;

        return [
            'id' => $visit->id,
            'state' => $visit->state,
            'scheduled_start' => $visit->scheduled_start?->toIso8601String(),
            'scheduled_end' => $visit->scheduled_end?->toIso8601String(),
            'on_site_seconds' => $visit->on_site_seconds,
            'is_rework' => $visit->is_rework,
            'work_order' => [
                'id' => $visit->workOrder?->id,
                'number' => $visit->workOrder?->wo_number,
                'type' => $visit->workOrder?->type,
                'priority' => $visit->workOrder?->priority,
                'title' => $visit->workOrder?->title,
                'description' => $visit->workOrder?->description,
            ],
            // The SLA countdown only runs inside the contract service window,
            // and stops for good once the work is finished (`live` false, with
            // the frozen `outcome`).
            'sla' => $sla ? [
                'due_at' => $due->toIso8601String(),
                'remaining_minutes' => $sla['remaining_minutes'],
                'status' => $sla['status'],
                'live' => $sla['live'],
                'outcome' => $sla['outcome'],
                'service_window' => [
                    'start' => $contract?->service_window_start ?? '07:00:00',
                    'end' => $contract?->service_window_end ?? '23:00:00',
                ],
            ] : null,
            'site' => [
                'id' => $visit->site->id,
                'name' => $visit->site->name,
                'client_name' => $visit->site->client->name,
                'address' => $visit->site->address,
                'lat' => $visit->site->lat,
                'lng' => $visit->site->lng,
                'geofence_radius_m' => $visit->site->geofence_radius_m,
                'dwell_threshold_s' => $visit->site->dwell_threshold_s,
                'access_notes' => $visit->site->access_notes,
            ],
            // The assets this visit was SCHEDULED to cover, not whatever the site
            // holds today. Sending the live list meant an asset added after
            // scheduling became required on the phone though the server had
            // frozen it out, and one deleted vanished from the phone while the
            // server still demanded it — a visit impossible to close either way.
            'assets' => app(RequiredAssets::class)->for($visit)->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'location' => $a->location_in_site,
                'qr_code' => $a->qr_code,
                'under_warranty' => $a->isUnderWarranty(),
                'warranty_until' => $a->warranty_until?->toDateString(),
            ])->values()->all(),
            'checklist_instances' => $visit->checklistInstances->map(fn ($c) => [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'status' => $c->status,
                'no_parts_used' => $c->no_parts_used,
            ])->all(),
            'stock_reservations' => $visit->stockReservations->where('status', 'reserved')->map(fn ($reservation) => [
                'part_id' => $reservation->part_id,
                'part_name' => $reservation->part?->name,
                'qty' => (float) $reservation->qty,
                'stock_location_id' => $reservation->stock_location_id,
                'location_name' => $reservation->location?->name,
            ])->values()->all(),
            'additional_work_approvals' => $visit->additionalWorkApprovals->map(fn ($approval) => [
                'id' => $approval->id,
                'title' => $approval->title,
                'total_amount' => (float) $approval->total_amount,
                'status' => $approval->status,
            ])->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Visit;
use App\Services\SlaCalculator;
use App\Services\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly SlaCalculator $sla,
    ) {
    }

    /**
     * Everything the device needs to work for a day with no network: today's and
     * tomorrow's visits, their sites, assets, checklist templates and the vehicle's
     * current part balances.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();

        // Today and tomorrow, PLUS anything still open regardless of date.
        //
        // A pure date window dropped yesterday's unfinished visit the moment the
        // clock passed midnight — a technician still on site at 00:20 watched the
        // job disappear from the device along with its assets and close button.
        $visits = Visit::with([
            'workOrder.contract', 'workOrder.asset', 'site.client', 'site.assets',
            'checklistInstances', 'mediaFiles',
        ])
            ->where('assigned_user_id', $user->id)
            ->where(fn ($q) => $q
                ->whereBetween('scheduled_start', [$now->startOfDay(), $now->addDay()->endOfDay()])
                ->orWhereNotIn('state', [Visit::STATE_COMPLETED]))
            ->orderBy('scheduled_start')
            ->get();

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'visits' => $visits->map(fn (Visit $v) => $this->visitPayload($v, $now))->all(),
        ]);
    }

    /**
     * Batch event ingest. Replay-safe: the same client_event_id may be sent any
     * number of times and will take effect exactly once.
     */
    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'last_trusted_server_time' => ['nullable', 'date'],
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.client_event_id' => ['required', 'uuid'],
            'events.*.visit_id' => ['required', 'integer'],
            'events.*.event_type' => ['required', 'string', 'max:48'],
            'events.*.payload' => ['nullable', 'array'],
            'events.*.device_timestamp' => ['nullable', 'date'],
            'events.*.monotonic_offset_ms' => ['nullable', 'integer'],
            'events.*.last_trusted_server_time' => ['nullable', 'date'],
            'events.*.sequence' => ['nullable', 'integer'],
            'events.*.lat' => ['nullable', 'numeric'],
            'events.*.lng' => ['nullable', 'numeric'],
            'events.*.source' => ['nullable', 'string', 'max:24'],
            'events.*.integrity_hash' => ['nullable', 'string', 'max:64'],
        ]);

        $device = Device::where('device_uuid', $data['device_uuid'])->firstOrFail();

        if ($device->user_id !== $request->user()->id) {
            return response()->json([
                'code' => 'DEVICE_MISMATCH',
                'message' => 'This device is registered to another user.',
            ], 403);
        }

        $result = $this->sync->ingest($device, $data['events'], $data['last_trusted_server_time'] ?? null);

        return response()->json($result);
    }

    /**
     * The frozen requirement, resolved to live rows for display.
     *
     * Falls back to the site's assets only when a visit predates the snapshot,
     * so an old row cannot end up requiring nothing at all.
     */
    private function requiredAssets(Visit $visit)
    {
        $required = $visit->required_asset_ids;

        if ($required === null || $required === []) {
            return $visit->site->assets;
        }

        return $visit->site->assets->whereIn('id', $required)->values();
    }

    private function visitPayload(Visit $visit, CarbonImmutable $now): array
    {
        $contract = $visit->workOrder?->contract;
        $due = $visit->workOrder?->sla_due_at;

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
            // The SLA countdown only runs inside the contract service window.
            'sla' => $due ? [
                'due_at' => $due->toIso8601String(),
                'remaining_minutes' => $this->sla->remainingMinutes($now, $due, $contract),
                'status' => $this->sla->status($now, $due, $visit->workOrder?->sla_minutes_budget ?? 240, $contract),
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
            'assets' => $this->requiredAssets($visit)->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'location' => $a->location_in_site,
                'qr_code' => $a->qr_code,
                'under_warranty' => $a->isUnderWarranty(),
                'warranty_until' => $a->warranty_until?->toDateString(),
            ])->all(),
            'checklist_instances' => $visit->checklistInstances->map(fn ($c) => [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'status' => $c->status,
                'no_parts_used' => $c->no_parts_used,
            ])->all(),
        ];
    }
}

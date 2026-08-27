<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Visit;
use App\Services\AdditionalWorkService;
use App\Services\AssetIntelligenceService;
use App\Services\AuditLogger;
use App\Services\CloseGate;
use App\Services\NotificationService;
use App\Services\ReworkDetector;
use App\Services\RoutePlanningService;
use App\Services\VisitStateMachine;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitController extends Controller
{
    public function __construct(
        private readonly VisitStateMachine $stateMachine,
        private readonly CloseGate $closeGate,
        private readonly ReworkDetector $rework,
        private readonly NotificationService $notifications,
        private readonly AssetIntelligenceService $intelligence,
        private readonly AdditionalWorkService $additionalWork,
        private readonly RoutePlanningService $routes,
        private readonly AuditLogger $audit,
        private readonly TenantAccess $tenantAccess,
    ) {}

    public function createAdditionalWork(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('update', $visit);
        abort_unless(in_array($visit->state, [Visit::STATE_STARTED, Visit::STATE_PAUSED], true), 422, 'يُنشأ طلب العمل الإضافي أثناء تنفيذ الزيارة فقط.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:1500'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'items.*.description' => ['required', 'string', 'max:190'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $approval = $this->additionalWork->create($visit, $data['title'], $data['description'], $data['items'], $request->user()->id, 'technician_app');

        return response()->json(['data' => $approval], 201);
    }

    public function updateLocation(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('update', $visit);
        abort_unless($visit->assigned_user_id === $request->user()->id, 403);
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $visit->loadMissing('site');
        $route = $this->routes->estimate((float) $data['lat'], (float) $data['lng'], $visit->site?->lat === null ? null : (float) $visit->site->lat, $visit->site?->lng === null ? null : (float) $visit->site->lng, now());
        $visit->forceFill([
            'technician_lat' => $data['lat'], 'technician_lng' => $data['lng'], 'location_updated_at' => now(),
            'estimated_arrival_at' => now()->addMinutes($route['minutes']), 'route_provider' => $route['provider'], 'route_distance_km' => $route['distance_km'],
        ])->save();

        return response()->json(['data' => ['location_updated_at' => $visit->location_updated_at, 'estimated_arrival_at' => $visit->estimated_arrival_at, 'route_provider' => $visit->route_provider]]);
    }

    public function diagnosisSuggestions(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $visit->loadMissing('workOrder.asset');
        abort_unless($visit->workOrder?->asset, 422, 'الزيارة غير مرتبطة بمعدة.');

        return response()->json([
            'data' => $this->intelligence->diagnosisSuggestions($visit->workOrder->asset, $request->string('fault_code')->toString() ?: $visit->workOrder->fault_code),
            'prediction_readiness' => $this->intelligence->predictionReadiness(),
        ]);
    }

    public function recordDiagnosis(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('update', $visit);
        $data = $request->validate(['fault_code' => ['required', 'string', 'max:64'], 'diagnosis_code' => ['required', 'string', 'max:64'], 'resolution_summary' => ['nullable', 'string', 'max:4000']]);
        $visit->loadMissing('workOrder');
        $before = $visit->workOrder->getAttributes();
        $visit->workOrder->forceFill($data)->save();
        $this->audit->recordChange('work_order.diagnosis_recorded', $visit->workOrder, $before, $request->user()->id);

        return response()->json(['data' => $visit->workOrder->refresh()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visit::class);

        $user = $request->user();

        $query = Visit::with(['workOrder', 'site.client', 'technician'])
            ->orderBy('scheduled_start');

        // A technician's list is their own, enforced here rather than in the UI.
        if ($user->isTechnician()) {
            $query->where('assigned_user_id', $user->id);
        }

        if ($request->filled('date')) {
            $date = CarbonImmutable::instance($request->date('date'));
            $query->whereBetween('scheduled_start', [$date->startOfDay(), $date->endOfDay()]);
        }

        if ($request->filled('state')) {
            $query->where('state', $request->string('state'));
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $visit->load(['workOrder.contract', 'site.client', 'site.assets', 'checklistInstances.asset', 'mediaFiles', 'stockMoves.part', 'stockReservations.part', 'additionalWorkApprovals', 'technician']);

        return response()->json([
            'data' => $visit,
            'close_blockers' => $this->closeGate->blockers($visit),
        ]);
    }

    public function transition(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('transition', $visit);

        $data = $request->validate([
            'to' => ['required', 'string', 'in:'.implode(',', array_keys(Visit::TRANSITIONS))],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:24'],
            'client_event_id' => ['nullable', 'uuid'],
        ]);

        $visit = $this->stateMachine->transition($visit, $data['to'], [
            'actor_user_id' => $request->user()->id,
            'device_id' => $request->attributes->get('device')?->id,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'source' => $data['source'] ?? 'api',
            'client_event_id' => $data['client_event_id'] ?? null,
        ]);

        return response()->json(['data' => $visit]);
    }

    public function closeBlockers(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $blockers = $this->closeGate->blockers($visit);

        return response()->json([
            'can_close' => $blockers === [],
            'blockers' => $blockers,
        ]);
    }

    public function assign(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('assign', Visit::class);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $technician = User::findOrFail($data['user_id']);
        $this->tenantAccess->assertUser($request->user(), $technician);

        // Manual dispatch, with hard guards only. The weighted auto-dispatch engine
        // is backlog — with four technicians a supervisor assigns faster than a
        // scoring model, and the model cannot be tuned without operating data.
        $conflicts = $this->conflicts($technician, $visit);

        if ($conflicts !== []) {
            return response()->json([
                'code' => 'ASSIGNMENT_BLOCKED',
                'message' => 'This technician cannot take the visit.',
                'reasons' => $conflicts,
            ], 422);
        }

        $previous = $visit->assigned_user_id;
        $visit->forceFill(['assigned_user_id' => $technician->id])->save();

        if ($previous !== $technician->id) {
            $visit->events()->create([
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'assignment.changed',
                'payload' => ['from' => $previous, 'to' => $technician->id],
                'actor_user_id' => $request->user()->id,
                'server_received_at' => now(),
                'source' => 'api',
            ]);
        }

        $this->notifications->visitAssigned($visit->refresh(), $technician);

        return response()->json(['data' => $visit->refresh()]);
    }

    public function overrideRework(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('overrideRework', Visit::class);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $visit = $this->rework->override($visit, $request->user()->id, $data['reason'], $data['note']);

        return response()->json(['data' => $visit]);
    }

    /** @return array<int, string> */
    private function conflicts(User $technician, Visit $visit): array
    {
        $reasons = [];

        if (! $technician->is_active) {
            $reasons[] = 'Technician is not active.';
        }
        if ($technician->role !== User::ROLE_TECHNICIAN) {
            $reasons[] = 'Selected user is not a technician.';
        }

        $start = $visit->scheduled_start;
        $end = $visit->scheduled_end ?? $start?->copy()->addHours(2);

        if ($start && $end && ! $technician->isWithinShift($start, $end)) {
            $reasons[] = 'Visit falls outside the technician\'s shift.';
        }

        $specialty = $visit->workOrder?->asset?->type;

        if ($specialty !== null && ($technician->specialties ?? []) !== [] && ! $technician->hasSpecialty($specialty)) {
            $reasons[] = "Technician is not listed for [{$specialty}]. Route it to a subcontractor.";
        }

        if ($start && $end) {
            $overlaps = Visit::where('assigned_user_id', $technician->id)
                ->where('id', '!=', $visit->id)
                ->whereNotIn('state', [Visit::STATE_COMPLETED])
                ->where('scheduled_start', '<', $end)
                ->where('scheduled_end', '>', $start)
                ->exists();

            if ($overlaps) {
                $reasons[] = 'Technician already has an overlapping visit.';
            }
        }

        return $reasons;
    }
}

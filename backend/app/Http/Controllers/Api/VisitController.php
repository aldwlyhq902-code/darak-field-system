<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\NotificationService;
use App\Services\ReworkDetector;
use App\Services\VisitStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function __construct(
        private readonly VisitStateMachine $stateMachine,
        private readonly CloseGate $closeGate,
        private readonly ReworkDetector $rework,
        private readonly NotificationService $notifications,
    ) {
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
            $query->whereDate('scheduled_start', $request->date('date'));
        }

        if ($request->filled('state')) {
            $query->where('state', $request->string('state'));
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $visit->load(['workOrder.contract', 'site.client', 'site.assets', 'checklistInstances.asset', 'mediaFiles', 'stockMoves.part', 'technician']);

        return response()->json([
            'data' => $visit,
            'close_blockers' => $this->closeGate->blockers($visit),
        ]);
    }

    public function transition(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('transition', $visit);

        $data = $request->validate([
            'to' => ['required', 'string', 'in:' . implode(',', array_keys(Visit::TRANSITIONS))],
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
                'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
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

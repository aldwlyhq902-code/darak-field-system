<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\ReworkDetector;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The supervisor's day.
 *
 * Manual assignment only — the weighted dispatch engine is backlog. With four
 * technicians a person assigns faster than a scoring model, and the model cannot
 * be tuned before there is operating data to tune it against.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly CloseGate $closeGate,
        private readonly ReworkDetector $rework,
        private readonly NotificationService $notifications,
    ) {
    }

    public function index(Request $request): View
    {
        $date = $request->date('date') ?? CarbonImmutable::now();
        $now = CarbonImmutable::now();

        $visits = Visit::with(['workOrder.contract', 'workOrder.client', 'site', 'technician'])
            ->whereDate('scheduled_start', $date)
            ->orderBy('scheduled_start')
            ->get()
            ->map(function (Visit $visit) use ($now) {
                $contract = $visit->workOrder?->contract;
                $due = $visit->workOrder?->sla_due_at;

                return [
                    'visit' => $visit,
                    'sla_status' => $due
                        ? $this->sla->status($now, $due, $visit->workOrder->sla_minutes_budget ?? 240, $contract)
                        : null,
                    'sla_remaining' => $due ? $this->sla->remainingMinutes($now, $due, $contract) : null,
                    'in_window' => $this->sla->isWithinWindow($now, $contract),
                ];
            });

        // Device freshness is a first-class supervision signal: a technician whose
        // phone has not synced for hours may be out of coverage, or may have the
        // app closed. Either way the board must not present stale data as live.
        $devices = Device::with('user')
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (Device $d) => [
                'device' => $d,
                'minutes_since_sync' => $d->last_sync_at?->diffInMinutes($now),
            ]);

        return view('panel.board', [
            'date' => $date,
            'rows' => $visits,
            'devices' => $devices,
            'counts' => $this->counts($visits),
            'firstTimeFix' => $this->rework->firstTimeFixRate($now->subDays(90), $now),
        ]);
    }

    public function show(Visit $visit, InventoryService $inventory): View
    {
        $visit->load([
            'workOrder.contract', 'workOrder.client', 'site.assets',
            'checklistInstances.asset', 'mediaFiles', 'stockMoves.part',
            'technician', 'events' => fn ($q) => $q->latest('id')->limit(60),
        ]);

        return view('panel.visit', [
            'visit' => $visit,
            'blockers' => $this->closeGate->blockers($visit),
            'consumption' => $inventory->visitConsumption($visit),
            'technicians' => User::where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->get(),
        ]);
    }

    public function assign(Request $request, Visit $visit): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $technician = User::findOrFail($data['user_id']);

        $reasons = $this->assignmentConflicts($technician, $visit);

        if ($reasons !== []) {
            return back()->with('err', 'تعذّر الإسناد: ' . implode(' · ', $reasons));
        }

        $previous = $visit->assigned_user_id;
        $visit->forceFill(['assigned_user_id' => $technician->id])->save();

        // Recorded so the notification key can be scoped to this assignment.
        // Without it, a visit moved A -> B -> A never notifies A the second time.
        if ($previous !== $technician->id) {
            $visit->events()->create([
                'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
                'event_type' => 'assignment.changed',
                'payload' => ['from' => $previous, 'to' => $technician->id],
                'actor_user_id' => $request->user()->id,
                'server_received_at' => now(),
                'source' => 'panel',
            ]);
        }

        // The technician has to learn about it — the app pulls, but a queued
        // message means the assignment is visible even before the next sync.
        $this->notifications->visitAssigned($visit->refresh(), $technician);

        return back()->with('ok', "أُسندت الزيارة إلى {$technician->name}.");
    }

    public function overrideRework(Request $request, Visit $visit): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $this->rework->override($visit, $request->user()->id, $data['reason'], $data['note']);

        return back()->with('ok', 'أُعيد تصنيف الزيارة، والسبب مسجل في سجل التدقيق.');
    }

    /** @return array<int, string> */
    private function assignmentConflicts(User $technician, Visit $visit): array
    {
        $reasons = [];
        $start = $visit->scheduled_start;
        $end = $visit->scheduled_end ?? $start?->copy()->addHours(2);

        if (! $technician->is_active) {
            $reasons[] = 'الفني غير نشط';
        }

        if ($start && $end && ! $technician->isWithinShift($start, $end)) {
            $reasons[] = 'الزيارة خارج ورديته';
        }

        $specialty = $visit->workOrder?->asset?->type;
        if ($specialty && ($technician->specialties ?? []) !== [] && ! $technician->hasSpecialty($specialty)) {
            $reasons[] = "غير مسجل لتخصص [{$specialty}] — وجّهها لمقاول باطن";
        }

        if ($start && $end) {
            $overlap = Visit::where('assigned_user_id', $technician->id)
                ->where('id', '!=', $visit->id)
                ->where('state', '!=', Visit::STATE_COMPLETED)
                ->where('scheduled_start', '<', $end)
                ->where('scheduled_end', '>', $start)
                ->exists();

            if ($overlap) {
                $reasons[] = 'لديه زيارة متداخلة في نفس الوقت';
            }
        }

        return $reasons;
    }

    private function counts($rows): array
    {
        return [
            'total' => $rows->count(),
            'open' => $rows->filter(fn ($r) => $r['visit']->state !== Visit::STATE_COMPLETED)->count(),
            'done' => $rows->filter(fn ($r) => $r['visit']->state === Visit::STATE_COMPLETED)->count(),
            'red' => $rows->filter(fn ($r) => $r['sla_status'] === 'red')->count(),
            'unassigned' => $rows->filter(fn ($r) => $r['visit']->assigned_user_id === null)->count(),
        ];
    }
}

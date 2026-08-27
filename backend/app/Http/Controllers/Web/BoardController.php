<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Models\Visit;
use App\Services\CloseGate;
use App\Services\DispatchSuggestionService;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\ReworkDetector;
use App\Services\RoutePlanningService;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The supervisor's day.
 *
 * Assignment remains a supervisor decision. Operations provides transparent
 * suggestions, while this endpoint rechecks every hard scheduling constraint.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly CloseGate $closeGate,
        private readonly ReworkDetector $rework,
        private readonly NotificationService $notifications,
        private readonly DispatchSuggestionService $dispatch,
        private readonly RoutePlanningService $routes,
    ) {}

    public function index(Request $request): View
    {
        $date = CarbonImmutable::instance($request->date('date') ?? CarbonImmutable::now());
        $now = CarbonImmutable::now();

        $visits = Visit::with(['workOrder.contract', 'workOrder.client', 'site', 'technician'])
            ->whereBetween('scheduled_start', [$date->startOfDay(), $date->endOfDay()])
            ->orderBy('scheduled_start')
            ->get()
            ->map(function (Visit $visit) use ($now) {
                $contract = $visit->workOrder?->contract;
                $due = $visit->workOrder?->sla_due_at;
                $slaStatus = $due
                    ? $this->sla->status($now, $due, $visit->workOrder->sla_minutes_budget ?? 240, $contract)
                    : null;
                $slaRemaining = $due ? $this->sla->remainingMinutes($now, $due, $contract) : null;
                $reasons = [];
                $priorityScore = 0;

                if ($slaStatus === 'red') {
                    $reasons[] = 'متجاوزة لاتفاقية الخدمة';
                    $priorityScore += 100;
                } elseif ($slaStatus === 'amber') {
                    $reasons[] = 'اتفاقية الخدمة تقترب';
                    $priorityScore += 60;
                }

                if ($visit->assigned_user_id === null && $visit->state !== Visit::STATE_COMPLETED) {
                    $reasons[] = 'تحتاج إسناد فني';
                    $priorityScore += 80;
                }

                if (in_array($visit->state, ['paused', 'reopened'], true)) {
                    $reasons[] = $visit->state === 'paused' ? 'العمل متوقف' : 'أُعيد فتحها';
                    $priorityScore += 50;
                }

                return [
                    'visit' => $visit,
                    'sla_status' => $slaStatus,
                    'sla_remaining' => $slaRemaining,
                    'in_window' => $this->sla->isWithinWindow($now, $contract),
                    'priority_score' => $priorityScore,
                    'priority_reasons' => $reasons,
                ];
            });

        // Device freshness is a first-class supervision signal: a technician whose
        // phone has not synced for hours may be out of coverage, or may have the
        // app closed. Either way the board must not present stale data as live.
        $devices = Device::with('user')
            ->when(! $request->user()->isPlatformAdmin(), fn ($query) => $query->whereHas('user', fn ($user) => $user
                ->where('operating_company_id', $request->user()->operating_company_id)
                ->when($request->user()->operating_branch_id, fn ($users, $branchId) => $users->where('operating_branch_id', $branchId))))
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (Device $d) => [
                'device' => $d,
                'minutes_since_sync' => $d->last_sync_at?->diffInMinutes($now),
            ])
            ->sortByDesc(fn (array $entry) => $entry['minutes_since_sync'] ?? PHP_INT_MAX)
            ->values();

        $counts = $this->counts($visits);
        $counts['stale_devices'] = $devices
            ->filter(fn (array $entry) => $entry['minutes_since_sync'] === null || $entry['minutes_since_sync'] > 60)
            ->count();

        return view('panel.board', [
            'date' => $date,
            'rows' => $visits,
            'devices' => $devices,
            'counts' => $counts,
            'priorities' => $visits
                ->filter(fn (array $row) => $row['priority_score'] > 0)
                ->sortByDesc('priority_score')
                ->values(),
            'completionRate' => $counts['total'] > 0
                ? (int) round(($counts['done'] / $counts['total']) * 100)
                : 0,
            'firstTimeFix' => $this->rework->firstTimeFixRate($now->subDays(90), $now),
        ]);
    }

    public function show(Request $request, Visit $visit, InventoryService $inventory): View
    {
        $visit->load([
            'workOrder.contract', 'workOrder.client', 'site.assets',
            'checklistInstances.asset', 'mediaFiles', 'stockMoves.part',
            'technician', 'events' => fn ($q) => $q->latest('id')->limit(60),
            'additionalWorkApprovals',
        ]);

        return view('panel.visit', [
            'visit' => $visit,
            'blockers' => $this->closeGate->blockers($visit),
            'consumption' => $inventory->visitConsumption($visit),
            'technicians' => $this->visibleTechnicians($request)->get(),
        ]);
    }

    public function assign(Request $request, Visit $visit): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $technician = $this->visibleTechnicians($request)->findOrFail($data['user_id']);

        $reasons = $this->dispatch->conflicts($technician, $visit->loadMissing('workOrder.asset'));

        if ($reasons !== []) {
            return back()->with('err', 'تعذّر الإسناد: '.implode(' · ', $reasons));
        }

        $previous = $visit->assigned_user_id;
        $visit->forceFill(['assigned_user_id' => $technician->id])->save();
        $this->routes->recalculateDay($technician, $visit->scheduled_start);

        // Recorded so the notification key can be scoped to this assignment.
        // Without it, a visit moved A -> B -> A never notifies A the second time.
        if ($previous !== $technician->id) {
            $visit->events()->create([
                'client_event_id' => (string) Str::uuid(),
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

    private function visibleTechnicians(Request $request)
    {
        $actor = $request->user();

        return User::query()
            ->where('role', User::ROLE_TECHNICIAN)
            ->where('is_active', true)
            ->when(! $actor->isPlatformAdmin(), fn ($query) => $query
                ->where('operating_company_id', $actor->operating_company_id)
                ->when($actor->operating_branch_id, fn ($users, $branchId) => $users->where('operating_branch_id', $branchId)));
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

    private function counts($rows): array
    {
        return [
            'total' => $rows->count(),
            'open' => $rows->filter(fn ($r) => $r['visit']->state !== Visit::STATE_COMPLETED)->count(),
            'done' => $rows->filter(fn ($r) => $r['visit']->state === Visit::STATE_COMPLETED)->count(),
            'red' => $rows->filter(fn ($r) => $r['sla_status'] === 'red')->count(),
            'amber' => $rows->filter(fn ($r) => $r['sla_status'] === 'amber')->count(),
            'unassigned' => $rows->filter(fn ($r) => $r['visit']->assigned_user_id === null)->count(),
        ];
    }
}

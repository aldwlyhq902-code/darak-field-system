<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ClientServiceRequest;
use App\Models\Part;
use App\Models\ReportDispute;
use App\Models\StockMove;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOutage;
use App\Models\Visit;
use App\Models\VisitFeedback;
use App\Services\AuditLogger;
use App\Services\AutomaticReassignmentService;
use App\Services\DispatchSuggestionService;
use App\Services\RoutePlanningService;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationsInsightsController extends Controller
{
    public function __construct(
        private readonly DispatchSuggestionService $dispatch,
        private readonly RoutePlanningService $routes,
        private readonly AutomaticReassignmentService $reassignment,
        private readonly AuditLogger $audit,
        private readonly TenantAccess $tenantAccess,
    ) {}

    public function calendar(Request $request): View
    {
        $tab = in_array($request->string('tab')->toString(), ['schedule', 'requests', 'quality', 'resources'], true)
            ? $request->string('tab')->toString()
            : 'schedule';

        $data = ['activeTab' => $tab];

        if ($tab === 'requests') {
            $data['serviceRequests'] = ClientServiceRequest::with(['client', 'site', 'asset', 'portalUser', 'visit'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->latest('id')
                ->limit(30)
                ->get();

            return view('panel.operations', $data);
        }

        if ($tab === 'quality') {
            $data['feedbackItems'] = VisitFeedback::with(['visit.site.client', 'portalUser'])
                ->whereHas('visit')
                ->where('is_complaint', true)
                ->latest('id')
                ->limit(30)
                ->get();
            $data['disputes'] = ReportDispute::with(['visit.site.client'])
                ->whereHas('visit')
                ->whereIn('status', ['new', 'reviewed'])
                ->latest()
                ->limit(30)
                ->get();
            $data['assetSignals'] = $this->assetSignals();
            $data['partSignals'] = $this->partSignals();

            return view('panel.operations', $data);
        }

        if ($tab === 'resources') {
            $data['technicians'] = User::where('role', User::ROLE_TECHNICIAN)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
            $data['absences'] = TechnicianAbsence::with('user')
                ->whereHas('user', fn ($query) => $query
                    ->when(! $request->user()->isPlatformAdmin(), fn ($user) => $user->where('operating_company_id', $request->user()->operating_company_id)))
                ->where('ends_on', '>=', now()->toDateString())
                ->latest()
                ->limit(30)
                ->get();
            $data['outages'] = VehicleOutage::with('vehicle.assignedUser')
                ->whereHas('vehicle')
                ->where('status', 'open')
                ->latest()
                ->get();
            $data['vehicles'] = Vehicle::with('assignedUser')->where('is_active', true)->get();

            return view('panel.operations', $data);
        }

        $mode = in_array($request->string('view')->toString(), ['day', 'week', 'month'], true) ? $request->string('view')->toString() : 'week';
        $anchor = $request->date('date') ?? $request->date('week') ?? CarbonImmutable::now();
        $start = match ($mode) {
            'day' => $anchor->startOfDay(),
            'month' => $anchor->startOfMonth(),
            default => $anchor->startOfWeek(),
        };
        $end = match ($mode) {
            'day' => $start->copy()->endOfDay(),
            'month' => $start->copy()->endOfMonth(),
            default => $start->copy()->addDays(6)->endOfDay(),
        };
        $dayCount = $start->diffInDays($end->copy()->startOfDay()) + 1;
        $technicians = $this->visibleTechnicians($request)->orderBy('name')->get();
        $visits = Visit::with(['site.client', 'workOrder.asset', 'technician'])
            ->whereBetween('scheduled_start', [$start, $end])->orderBy('scheduled_start')->get();
        $suggestions = $visits->whereNull('assigned_user_id')->mapWithKeys(fn (Visit $visit) => [$visit->id => $this->dispatch->suggest($visit)]);

        return view('panel.operations', $data + [
            'start' => $start, 'end' => $end, 'dayCount' => $dayCount, 'viewMode' => $mode, 'technicians' => $technicians, 'visits' => $visits,
            'suggestions' => $suggestions,
        ]);
    }

    public function reschedule(Request $request, Visit $visit): RedirectResponse
    {
        $data = $request->validate(['assigned_user_id' => ['required', 'exists:users,id'], 'scheduled_start' => ['required', 'date'], 'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720']]);
        $technician = $this->visibleTechnicians($request)->findOrFail($data['assigned_user_id']);
        $oldTechnician = $visit->technician;
        $oldDay = $visit->scheduled_start;
        $start = CarbonImmutable::parse($data['scheduled_start']);
        $duration = (int) ($data['duration_minutes'] ?? max(15, $visit->scheduled_start?->diffInMinutes($visit->scheduled_end) ?? 120));
        $candidate = $visit->replicate()->forceFill(['id' => $visit->id, 'assigned_user_id' => $technician->id, 'scheduled_start' => $start, 'scheduled_end' => $start->addMinutes($duration)]);
        $conflicts = $this->dispatch->conflicts($technician, $candidate);
        if ($conflicts !== []) {
            return back()->with('err', implode('، ', $conflicts));
        }
        $before = $visit->getAttributes();
        $visit->forceFill(['assigned_user_id' => $technician->id, 'scheduled_start' => $start, 'scheduled_end' => $start->addMinutes($duration)])->save();
        if ($oldTechnician && $oldDay) {
            $this->routes->recalculateDay($oldTechnician, $oldDay);
        }
        $this->routes->recalculateDay($technician, $start);
        $this->audit->recordChange('visit.rescheduled', $visit, $before, $request->user()->id);

        return back()->with('ok', 'أُعيدت جدولة الزيارة مع التحقق من الوردية وزمن الطريق والتعارضات.');
    }

    public function absence(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'max:64']]);
        $this->visibleTechnicians($request)->findOrFail($data['user_id']);
        TechnicianAbsence::create($data + ['status' => 'approved', 'approved_by' => $request->user()->id]);
        $count = $this->reassignment->run();

        return back()->with('ok', "سُجل الغياب وأُعيد توزيع {$count} زيارة متأثرة.");
    }

    public function outage(Request $request): RedirectResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'exists:vehicles,id'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'reason' => ['required', 'string', 'max:255']]);
        VehicleOutage::create($data + ['status' => 'open', 'reported_by' => $request->user()->id]);
        Vehicle::whereKey($data['vehicle_id'])->update(['operational_status' => 'out_of_service']);
        $count = $this->reassignment->run();

        return back()->with('ok', "سُجل تعطل السيارة وأُعيد توزيع {$count} زيارة متأثرة.");
    }

    public function resolveDispute(Request $request, ReportDispute $dispute): RedirectResponse
    {
        $this->tenantAccess->assertDispute($request->user(), $dispute);
        $data = $request->validate(['status' => ['required', 'in:reviewed,resolved,rejected'], 'resolution_note' => ['required', 'string', 'max:3000']]);
        $dispute->forceFill(['status' => $data['status'], 'resolution_note' => $data['resolution_note'], 'resolved_by' => $request->user()->id, 'resolved_at' => $data['status'] === 'reviewed' ? null : now()])->save();
        $this->audit->record('report_dispute.'.$data['status'], $dispute, null, $data, $request->user()->id);

        return back()->with('ok', 'حُدثت حالة اعتراض العميل.');
    }

    private function assetSignals()
    {
        $faultsInYear = fn ($q) => $q->whereIn('type', ['reactive', 'out_of_contract'])
            ->where('reported_at', '>=', now()->subYear());

        return Asset::query()->with('site.client')
            ->whereHas('workOrders', $faultsInYear, '>=', 2)
            ->withCount([
                'workOrders as faults_90d' => fn ($q) => $q->whereIn('type', ['reactive', 'out_of_contract'])->where('reported_at', '>=', now()->subDays(90)),
                'workOrders as faults_365d' => $faultsInYear,
            ])->orderByDesc('faults_90d')->limit(30)->get()->map(function ($asset) {
                $asset->signal = $asset->faults_90d >= 3 ? 'critical' : ($asset->faults_365d >= 3 ? 'watch' : 'observe');

                return $asset;
            });
    }

    private function partSignals()
    {
        return Part::query()->select('parts.*')
            ->whereHas('stockMoves', fn ($query) => $query
                ->where('move_type', StockMove::VISIT_ISSUE)
                ->where('created_at', '>=', now()->subDays(90)), '>=', 2)
            ->selectSub(StockMove::query()->selectRaw('COUNT(*)')->whereColumn('stock_moves.part_id', 'parts.id')->where('move_type', StockMove::VISIT_ISSUE)->where('created_at', '>=', now()->subDays(90)), 'issues_90d')
            ->selectSub(StockMove::query()->selectRaw('COUNT(DISTINCT visit_id)')->whereColumn('stock_moves.part_id', 'parts.id')->where('move_type', StockMove::VISIT_ISSUE)->where('created_at', '>=', now()->subDays(90)), 'visits_90d')
            ->orderByDesc('issues_90d')->limit(20)->get();
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
}

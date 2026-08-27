<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ClientServiceRequest;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientServiceRequestController extends Controller
{
    private const SLOTS = [
        'morning' => ['07:00', '11:00', 'صباحًا 07:00–11:00'],
        'afternoon' => ['11:00', '15:00', 'ظهرًا 11:00–15:00'],
        'evening' => ['15:00', '19:00', 'مساءً 15:00–19:00'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): View
    {
        abort_unless($request->user('client')->canPortal('service.request'), 403);
        $client = $request->user('client')->client->load('sites.assets');
        $allowed = $request->user('client')->allowedSites()->pluck('sites.id');
        if ($allowed->isNotEmpty()) {
            $client->setRelation('sites', $client->sites->whereIn('id', $allowed));
        }
        $rangeStart = CarbonImmutable::today()->addDay()->startOfDay();
        $rangeEnd = CarbonImmutable::today()->addDays(14)->endOfDay();
        $technicians = User::query()->where('role', User::ROLE_TECHNICIAN)->where('is_active', true)
            ->where('operating_company_id', $client->operating_company_id)
            ->when($client->operating_branch_id, fn ($query, int $branchId) => $query->where('operating_branch_id', $branchId))
            ->get();
        $bookedVisits = Visit::query()->where('state', '!=', Visit::STATE_COMPLETED)
            ->where('scheduled_start', '<', $rangeEnd)
            ->where('scheduled_end', '>', $rangeStart)
            ->when($client->operating_branch_id, fn ($query, int $branchId) => $query->whereHas(
                'site.client', fn ($clientQuery) => $clientQuery->where('operating_branch_id', $branchId)
            ))
            ->get(['scheduled_start', 'scheduled_end']);

        $capacity = collect(range(1, 14))->map(function (int $offset) use ($technicians, $bookedVisits) {
            $date = CarbonImmutable::today()->addDays($offset);

            return [
                'date' => $date,
                'slots' => collect(self::SLOTS)->map(function ($slot, $key) use ($date, $technicians, $bookedVisits) {
                    [$start, $end, $label] = $slot;
                    $startAt = CarbonImmutable::parse($date->toDateString().' '.$start);
                    $endAt = CarbonImmutable::parse($date->toDateString().' '.$end);
                    $availableTechnicians = $technicians->filter(fn (User $user) => $user->isWithinShift($startAt, $endAt))->count();
                    $booked = $bookedVisits->filter(fn (Visit $visit) => $visit->scheduled_start?->lt($endAt)
                        && $visit->scheduled_end?->gt($startAt))->count();

                    return ['key' => $key, 'label' => $label, 'available' => max(0, $availableTechnicians - $booked)];
                })->values(),
            ];
        });

        return view('client.request-service', compact('client', 'capacity'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user('client')->canPortal('service.request'), 403);
        $clientId = $request->user('client')->client_id;
        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('client_id', $clientId)],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')->where('site_id', $request->integer('site_id'))],
            'category' => ['required', 'in:preventive,reactive,inspection'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'preferred_date' => ['required', 'date', 'after:today', 'before_or_equal:'.today()->addDays(14)->toDateString()],
            'preferred_time_slot' => ['required', Rule::in(array_keys(self::SLOTS))],
        ]);
        abort_unless($request->user('client')->canAccessSite((int) $data['site_id']), 403);

        $serviceRequest = ClientServiceRequest::create($data + [
            'public_reference' => (string) Str::uuid(), 'client_id' => $clientId,
            'client_portal_user_id' => $request->user('client')->id, 'status' => ClientServiceRequest::STATUS_PENDING,
        ]);
        $this->audit->record('client_service_request.created', $serviceRequest, null, ['client_portal_user_id' => $request->user('client')->id], null);

        return redirect()->route('client.home')->with('ok', 'تم إرسال طلب الموعد. سيظهر لك تأكيد المشرف بعد المراجعة.');
    }

    public function convert(Request $request, ClientServiceRequest $serviceRequest): RedirectResponse
    {
        $data = $request->validate(['response_note' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($request, $serviceRequest, $data) {
            $serviceRequest = ClientServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);
            abort_unless($serviceRequest->status === ClientServiceRequest::STATUS_PENDING, 422);
            [$start, $end] = self::SLOTS[$serviceRequest->preferred_time_slot];
            $startAt = CarbonImmutable::parse($serviceRequest->preferred_date->toDateString().' '.$start);
            $endAt = CarbonImmutable::parse($serviceRequest->preferred_date->toDateString().' '.$end);
            $workOrder = WorkOrder::create([
                'wo_number' => 'WO-CR-'.strtoupper(Str::random(10)),
                'client_id' => $serviceRequest->client_id, 'site_id' => $serviceRequest->site_id,
                'asset_id' => $serviceRequest->asset_id, 'type' => $serviceRequest->category,
                'priority' => 'normal', 'title' => 'طلب خدمة من بوابة العميل',
                'description' => $serviceRequest->description, 'reported_at' => $serviceRequest->created_at,
                'status' => 'scheduled', 'created_by' => $request->user()->id,
            ]);
            $visit = $workOrder->visits()->create([
                'site_id' => $serviceRequest->site_id, 'scheduled_start' => $startAt,
                'scheduled_end' => $endAt, 'state' => Visit::STATE_SCHEDULED,
                'state_changed_at' => now(), 'required_asset_ids' => $serviceRequest->asset_id ? [$serviceRequest->asset_id] : [],
            ]);
            $serviceRequest->forceFill([
                'status' => ClientServiceRequest::STATUS_CONVERTED, 'visit_id' => $visit->id,
                'response_note' => $data['response_note'] ?? null, 'responded_by' => $request->user()->id, 'responded_at' => now(),
            ])->save();
            $this->audit->record('client_service_request.converted', $serviceRequest, null, ['visit_id' => $visit->id], $request->user()->id);
        });

        return back()->with('ok', 'تم اعتماد الطلب وتحويله إلى زيارة مجدولة.');
    }

    public function reject(Request $request, ClientServiceRequest $serviceRequest): RedirectResponse
    {
        abort_unless($serviceRequest->status === ClientServiceRequest::STATUS_PENDING, 422);
        $data = $request->validate(['response_note' => ['required', 'string', 'max:1000']]);
        $serviceRequest->forceFill(['status' => ClientServiceRequest::STATUS_REJECTED, 'response_note' => $data['response_note'], 'responded_by' => $request->user()->id, 'responded_at' => now()])->save();
        $this->audit->record('client_service_request.rejected', $serviceRequest, null, ['response_note' => $data['response_note']], $request->user()->id);

        return back()->with('ok', 'تم رفض الطلب مع تسجيل السبب.');
    }
}

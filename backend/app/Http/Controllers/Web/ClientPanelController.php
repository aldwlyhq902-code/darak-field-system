<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Visit;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Data entry for clients, sites, assets and contracts — the owner needs this
 * before a single visit can exist, and it is the part a spreadsheet cannot do
 * because every record has to carry the ids the field app depends on.
 */
class ClientPanelController extends Controller
{
    public function __construct(private readonly SlaCalculator $sla)
    {
    }

    public function index(): View
    {
        return view('panel.clients', [
            'clients' => Client::withCount(['sites', 'contracts'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'category' => ['required', 'in:restaurant,cafe,central_kitchen,chain'],
            'cr_number' => ['nullable', 'string', 'max:32'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'established_on' => ['nullable', 'date'],
            'site_name' => ['required', 'string', 'max:190'],
            'site_address' => ['nullable', 'string', 'max:190'],
        ]);

        $client = Client::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'cr_number' => $data['cr_number'] ?? null,
            'vat_number' => $data['vat_number'] ?? null,
            'established_on' => $data['established_on'] ?? null,
            // Credit policy, applied by the system rather than remembered:
            // a restaurant under a year old pays quarterly in advance.
            'payment_term' => 'quarterly_advance',
        ]);

        $client->sites()->create([
            'name' => $data['site_name'],
            'address' => $data['site_address'] ?? null,
            'qr_code' => 'SITE-' . strtoupper(Str::random(8)),
        ]);

        $message = $client->requiresAdvancePayment()
            ? 'أُضيف العميل. تنبيه: المنشأة عمرها أقل من سنة — الدفع ربع سنوي مقدماً.'
            : 'أُضيف العميل وموقعه الأول.';

        return redirect()->route('panel.client', $client)->with('ok', $message);
    }

    public function show(Client $client): View
    {
        $client->load(['sites.assets', 'contracts.sites']);

        return view('panel.client', ['client' => $client]);
    }

    public function storeSite(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'access_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $client->sites()->create($data + ['qr_code' => 'SITE-' . strtoupper(Str::random(8))]);

        return back()->with('ok', 'أُضيف الموقع.');
    }

    public function storeAsset(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'string', 'max:32'],
            'manufacturer' => ['nullable', 'string', 'max:96'],
            'model' => ['nullable', 'string', 'max:96'],
            'serial_number' => ['nullable', 'string', 'max:96'],
            'location_in_site' => ['nullable', 'string', 'max:190'],
            'warranty_until' => ['nullable', 'date'],
        ]);

        $site->assets()->create($data + ['qr_code' => 'ASSET-' . strtoupper(Str::random(8))]);

        return back()->with('ok', 'أُضيف الأصل. اطبع ملصق QR وألصقه على الجهاز.');
    }

    public function storeContract(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'package_code' => ['required', 'in:basic,comprehensive'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'sla_minutes' => ['required', 'integer', 'min:30', 'max:2880'],
            'service_window_start' => ['required'],
            'service_window_end' => ['required'],
            'is_trial' => ['nullable', 'boolean'],
            'site_ids' => ['required', 'array', 'min:1'],
            'site_ids.*' => ['exists:sites,id'],
        ]);

        $contract = $client->contracts()->create([
            'contract_no' => 'DK-' . str_pad((string) (Contract::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'package_code' => $data['package_code'],
            // Stored PRE-VAT, always. The gross figure is derived, never entered.
            'price_amount' => $data['price_amount'],
            'vat_rate' => 0.15,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'sla_minutes' => $data['sla_minutes'],
            'service_window_start' => $data['service_window_start'],
            'service_window_end' => $data['service_window_end'],
            'is_trial' => $request->boolean('is_trial'),
            'decision_due_on' => $request->boolean('is_trial')
                ? CarbonImmutable::parse($data['starts_on'])->addDays(90)->toDateString()
                : null,
            'exclusions' => [
                'الأعطال القائمة قبل التعاقد',
                'الأعمال بعد نهاية نافذة الخدمة',
                'تلف البضاعة',
                'المعدات الساخنة (أفران وقلايات)',
                'سقف زيارات الطوارئ لا يتراكم شهرياً',
            ],
            'status' => 'active',
        ]);

        $contract->sites()->sync($data['site_ids']);

        return back()->with('ok', "أُنشئ العقد {$contract->contract_no} بقيمة {$contract->price_amount} قبل الضريبة (شامل الضريبة: {$contract->priceInclVat()}).");
    }

    /** Creates a work order and its visit in one step — the common daily action. */
    public function storeVisit(Request $request, Client $client): RedirectResponse
    {
        // Every relation is scoped to THIS client. A bare `exists` let an order be
        // built from client A's site with client B's contract and asset — the
        // resulting invoice would bill the wrong company for the wrong equipment.
        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'contract_id' => ['nullable', Rule::exists('contracts', 'id')->where('client_id', $client->id)],
            // Bound to the SELECTED site, not merely to the client. Any-site-of-
            // this-client let a visit for branch A carry a snapshot naming an
            // asset at branch B: bootstrap filtered it out and sent an empty
            // list while CloseGate went on demanding it.
            'asset_id' => [
                'nullable',
                Rule::exists('assets', 'id')->where(
                    fn ($q) => $q->where('site_id', $request->input('site_id'))
                ),
            ],
            'type' => ['required', 'in:preventive,reactive,out_of_contract'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scheduled_start' => ['required', 'date'],
            'assigned_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('role', User::ROLE_TECHNICIAN)->where('is_active', true),
            ],
        ]);

        $contract = ! empty($data['contract_id']) ? Contract::find($data['contract_id']) : null;

        if ($contract !== null && ! $contract->sites->contains('id', (int) $data['site_id'])) {
            return back()
                ->withInput()
                ->withErrors(['contract_id' => 'هذا العقد لا يغطي الموقع المختار.']);
        }

        $reportedAt = CarbonImmutable::now();
        $budget = $contract?->sla_minutes ?? 480;

        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-' . str_pad((string) (WorkOrder::max('id') + 1), 5, '0', STR_PAD_LEFT),
            'client_id' => $client->id,
            'site_id' => $data['site_id'],
            'contract_id' => $contract?->id,
            'asset_id' => $data['asset_id'] ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'reported_at' => $reportedAt,
            'sla_minutes_budget' => $budget,
            // The due time consumes only in-window minutes.
            'sla_due_at' => $this->sla->dueAt($reportedAt, $budget, $contract),
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]);

        $start = CarbonImmutable::parse($data['scheduled_start']);

        // A reactive order about one unit requires that unit; a preventive round
        // requires every asset on the site. Frozen now, so the visit is judged
        // against what it was scheduled to cover — not against assets added later.
        // A nullable rule that is not submitted leaves the key ABSENT, not null.
        $requiredAssetIds = ! empty($data['asset_id'])
            ? [(int) $data['asset_id']]
            : Asset::where('site_id', $data['site_id'])->pluck('id')->all();

        Visit::create([
            'work_order_id' => $workOrder->id,
            'site_id' => $data['site_id'],
            'required_asset_ids' => $requiredAssetIds,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'scheduled_start' => $start,
            'scheduled_end' => $start->addHours(2),
            'state' => Visit::STATE_SCHEDULED,
            'state_changed_at' => CarbonImmutable::now(),
        ]);

        return back()->with('ok', "أُنشئ أمر العمل {$workOrder->wo_number} وزيارته.");
    }
}

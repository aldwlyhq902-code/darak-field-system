<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\ReportDispute;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\VisitReportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Mpdf\Mpdf;

class ClientPortalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function home(Request $request): View
    {
        $client = $request->user('client')->client;
        $portal = $request->user('client');
        $client->load(['sites.assets', 'contracts.sites', 'contracts.installments.payments', 'quotations.sites', 'additionalWorkApprovals.visit.site', 'serviceRequests.site', 'serviceRequests.asset', 'serviceRequests.visit', 'payments.installment.contract']);
        $allowedSiteIds = $portal->allowedSites()->pluck('sites.id');
        if ($allowedSiteIds->isNotEmpty()) {
            $client->setRelation('sites', $client->sites->whereIn('id', $allowedSiteIds));
            $client->setRelation('contracts', $client->contracts->filter(fn ($contract) => $contract->sites->pluck('id')->diff($allowedSiteIds)->isEmpty()));
            $client->setRelation('quotations', $client->quotations->filter(fn ($quotation) => $quotation->sites->pluck('id')->diff($allowedSiteIds)->isEmpty()));
            $client->setRelation('additionalWorkApprovals', $client->additionalWorkApprovals->filter(fn ($approval) => $allowedSiteIds->contains($approval->visit?->site_id)));
            $client->setRelation('serviceRequests', $client->serviceRequests->whereIn('site_id', $allowedSiteIds));
            $allowedContractIds = $client->contracts->pluck('id');
            $client->setRelation('payments', $client->payments->filter(fn ($payment) => $allowedContractIds->contains($payment->installment?->contract_id)));
        }

        $visits = Visit::with(['workOrder', 'site', 'technician'])
            ->whereHas('workOrder', fn ($query) => $query->where('client_id', $client->id))
            ->when($allowedSiteIds->isNotEmpty(), fn ($query) => $query->whereIn('site_id', $allowedSiteIds))
            ->latest('id')
            ->limit(15)
            ->get();

        return view('client.home', [
            'client' => $client,
            'visits' => $visits,
            'quotations' => $client->quotations->whereIn('status', [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED, Quotation::STATUS_CONVERTED])->sortByDesc('id'),
            'additionalApprovals' => $client->additionalWorkApprovals->sortByDesc('id'),
            'serviceRequests' => $client->serviceRequests->sortByDesc('id'),
        ]);
    }

    public function quotation(Request $request, Quotation $quotation): View
    {
        abort_unless($quotation->client_id === $request->user('client')->client_id, 404);
        abort_if($quotation->status === Quotation::STATUS_DRAFT, 404);
        abort_if($quotation->superseded_at !== null, 410, 'هذا الإصدار مستبدل بإصدار أحدث.');
        $this->ensurePortalCanAccessSites($request, $quotation->sites()->pluck('sites.id'));

        return view('client.quotation', ['quotation' => $quotation->load(['client', 'sites', 'convertedContract.installments'])]);
    }

    public function acceptQuotation(Request $request, Quotation $quotation)
    {
        abort_unless($quotation->client_id === $request->user('client')->client_id, 404);
        $this->ensurePortalCanAccessSites($request, $quotation->sites()->pluck('sites.id'));
        abort_unless($quotation->status === Quotation::STATUS_SENT, 422);
        abort_if($quotation->valid_until->isPast(), 422, 'انتهت صلاحية العرض.');
        $request->validate(['accept_terms' => ['accepted']]);

        abort_unless($request->user('client')->canPortal('quotes.approve'), 403);
        DB::transaction(function () use ($request, $quotation) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->ensurePortalCanAccessSites($request, $quotation->sites()->pluck('sites.id'));
            abort_if($quotation->superseded_at !== null, 410);
            abort_unless($quotation->status === Quotation::STATUS_SENT, 422);
            abort_if($quotation->valid_until->isPast(), 422);
            Quotation::where('series_uuid', $quotation->series_uuid)->where('id', '!=', $quotation->id)->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $quotation->forceFill([
                'status' => Quotation::STATUS_ACCEPTED, 'accepted_at' => now(),
                'accepted_by_portal_user_id' => $request->user('client')->id,
                'acceptance_ip_hash' => hash('sha256', (string) $request->ip()),
            ])->save();
        });
        $this->audit->record('quotation.accepted', $quotation, null, [
            'client_portal_user_id' => $request->user('client')->id,
        ], null);

        return back()->with('ok', 'تم اعتماد العرض. ستقوم الإدارة بتحويله إلى عقد وجدول دفعات.');
    }

    public function contract(Request $request, Contract $contract): View
    {
        abort_unless($contract->client_id === $request->user('client')->client_id, 404);
        $this->ensurePortalCanAccessSites($request, $contract->sites()->pluck('sites.id'));

        return view('client.contract', ['contract' => $contract->load(['client', 'sites', 'installments.payments'])]);
    }

    public function signContract(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->client_id === $request->user('client')->client_id, 404);
        $this->ensurePortalCanAccessSites($request, $contract->sites()->pluck('sites.id'));
        abort_unless($request->user('client')->canPortal('contracts.sign'), 403);
        $data = $request->validate(['signed_name' => ['required', 'string', 'max:190'], 'accept_terms' => ['accepted']]);
        $hash = DB::transaction(function () use ($request, $contract, $data): string {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->ensurePortalCanAccessSites($request, $contract->sites()->pluck('sites.id'));
            abort_if($contract->signed_at !== null, 422, 'العقد موقع مسبقًا.');
            $signedAt = now();
            $hash = hash('sha256', implode('|', [$contract->id, $request->user('client')->id, $data['signed_name'], $signedAt->toIso8601String(), hash('sha256', (string) $request->ip())]));
            $contract->forceFill(['signed_by_portal_user_id' => $request->user('client')->id, 'signed_name' => $data['signed_name'], 'signature_hash' => $hash, 'signed_at' => $signedAt])->save();

            return $hash;
        });
        $this->audit->record('contract.signed', $contract, null, ['client_portal_user_id' => $request->user('client')->id, 'signature_hash' => $hash], null);

        return back()->with('ok', 'تم توقيع العقد إلكترونيًا وحفظ بصمة التوقيع في سجل التدقيق.');
    }

    public function visit(Request $request, Visit $visit): View
    {
        $this->ensureOwnsVisit($request, $visit);
        $visit->load(['workOrder', 'site', 'technician', 'checklistInstances.asset', 'feedback']);
        $disputes = ReportDispute::where('visit_id', $visit->id)->where('client_portal_user_id', $request->user('client')->id)->latest()->get();

        return view('client.visit', ['visit' => $visit, 'disputes' => $disputes]);
    }

    public function dispute(Request $request, Visit $visit): RedirectResponse
    {
        $this->ensureOwnsVisit($request, $visit);
        abort_unless($request->user('client')->canPortal('reports.dispute'), 403);
        $data = $request->validate(['category' => ['required', 'in:work,parts,time,report,other'], 'description' => ['required', 'string', 'max:4000']]);
        $dispute = ReportDispute::create($data + ['public_reference' => (string) Str::uuid(), 'visit_id' => $visit->id, 'client_portal_user_id' => $request->user('client')->id, 'status' => 'new']);
        $this->audit->record('report.disputed', $dispute, null, $dispute->toArray(), null);

        return back()->with('ok', 'سُجل اعتراضك برقم '.$dispute->public_reference.' وسيظهر للمشرف للمتابعة.');
    }

    public function assetHistory(Request $request, Asset $asset): Response
    {
        abort_unless($asset->site?->client_id === $request->user('client')->client_id, 404);
        abort_unless($request->user('client')->canAccessSite($asset->site_id), 403);
        abort_unless($request->user('client')->canPortal('assets.history'), 403);
        $asset->load(['site.client', 'workOrders.visits.technician', 'workOrders.visits.stockMoves.part']);
        $html = view('reports.asset-history', compact('asset'))->render();
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl', 'tempDir' => storage_path('app/mpdf')]);
        $pdf->WriteHTML($html);

        return response($pdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="asset-'.$asset->id.'-history.pdf"']);
    }

    public function report(Request $request, Visit $visit, VisitReportBuilder $builder): Response
    {
        $this->ensureOwnsVisit($request, $visit);
        abort_unless($visit->isClosed(), 404);

        return response($builder->render($visit), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="darak-visit-'.$visit->id.'.pdf"',
        ]);
    }

    private function ensureOwnsVisit(Request $request, Visit $visit): void
    {
        $visit->loadMissing('workOrder');
        abort_unless($visit->workOrder?->client_id === $request->user('client')->client_id, 404);
        abort_unless($request->user('client')->canAccessSite($visit->site_id), 403);
    }

    private function ensurePortalCanAccessSites(Request $request, $siteIds): void
    {
        $portal = $request->user('client');
        $restricted = $portal->allowedSites()->pluck('sites.id');
        if ($restricted->isNotEmpty()) {
            // A commercial document can span multiple sites. Partial overlap must
            // not reveal or authorise the portions belonging to restricted sites.
            abort_if($siteIds->diff($restricted)->isNotEmpty(), 403);
        }
    }
}

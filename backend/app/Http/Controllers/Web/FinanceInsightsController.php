<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\OperationalCost;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\ProfitabilityService;
use App\Support\Money;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Mpdf\Mpdf;

class FinanceInsightsController extends Controller
{
    private const RECENT_VISITS = 40;

    public function __construct(private readonly ProfitabilityService $profitability, private readonly AuditLogger $audit, private readonly TenantAccess $tenantAccess) {}

    public function index(Request $request): View
    {
        // Both lists are paginated with their own page names; every figure on a
        // row is computed for that row only, so nothing depends on the page.
        $contractModels = Contract::with('client')->whereIn('status', ['active', 'ended'])
            ->latest('id')->paginate(25, ['*'], 'contracts_page')->withQueryString();
        $contractProfits = $this->profitability->forContracts($contractModels->getCollection());
        $contracts = $contractModels->through(fn ($contract) => ['contract' => $contract, 'profit' => $contractProfits[$contract->id]]);
        $clientBalances = Client::query()
            ->leftJoin('contracts', 'contracts.client_id', '=', 'clients.id')
            ->leftJoin('contract_installments', 'contract_installments.contract_id', '=', 'contracts.id')
            ->select('clients.*')
            ->selectRaw('COALESCE(SUM(contract_installments.total_amount), 0) AS billed_total')
            ->selectRaw('COALESCE(SUM(contract_installments.paid_amount), 0) AS paid_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN contract_installments.due_on < ? AND contract_installments.status != 'paid' THEN contract_installments.total_amount - contract_installments.paid_amount ELSE 0 END), 0) AS overdue_total", [today()->toDateString()])
            ->groupBy('clients.id')
            ->orderBy('clients.name')
            ->paginate(25, ['*'], 'balances_page')->withQueryString()
            ->through(fn ($client) => [
                'client' => $client,
                'billed' => Money::of($client->billed_total),
                'paid' => Money::of($client->paid_total),
                'overdue' => Money::of($client->overdue_total),
            ]);

        // Deliberately a "latest 40 completed visits" widget (labelled as such),
        // not a list: the full history lives on the visit pages.
        $visitModels = Visit::with(['site.client', 'technician'])->where('state', Visit::STATE_COMPLETED)->latest('closed_at')->limit(self::RECENT_VISITS)->get();
        $visitProfits = $this->profitability->forVisitModels($visitModels);
        $technicianModels = User::where('role', User::ROLE_TECHNICIAN)
            ->when(! $request->user()->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $request->user()->operating_company_id))
            ->get();
        $technicianProfits = $this->profitability->forTechnicians($technicianModels, now()->startOfMonth(), now()->endOfMonth());

        return view('panel.finance', [
            'contracts' => $contracts, 'clientBalances' => $clientBalances, 'recentVisitsLimit' => self::RECENT_VISITS,
            'visits' => $visitModels->map(fn ($visit) => ['visit' => $visit, 'profit' => $visitProfits[$visit->id]]),
            'technicians' => $technicianModels->map(fn ($user) => ['user' => $user, 'profit' => $technicianProfits[$user->id]]),
        ]);
    }

    public function cost(Request $request): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'exists:clients,id'], 'contract_id' => ['nullable', 'exists:contracts,id'], 'contract_no' => ['nullable', 'string', 'max:64'], 'visit_id' => ['nullable', 'exists:visits,id'], 'user_id' => ['nullable', 'exists:users,id'], 'category' => ['required', 'in:travel,vehicle,tool,administration,other'], 'description' => ['required', 'string', 'max:190'], 'amount' => ['required', 'numeric', 'gt:0'], 'incurred_on' => ['required', 'date']]);
        // The form takes the contract number instead of a (truncated) select of every contract.
        if (filled($data['contract_no'] ?? null)) {
            $data['contract_id'] = Contract::query()->where('contract_no', trim($data['contract_no']))->firstOrFail()->id;
        }
        unset($data['contract_no']);
        $data['amount'] = Money::of($data['amount']);
        if (isset($data['client_id'])) {
            Client::query()->findOrFail($data['client_id']);
        }
        if (isset($data['contract_id'])) {
            Contract::query()->findOrFail($data['contract_id']);
        }
        if (isset($data['visit_id'])) {
            Visit::query()->findOrFail($data['visit_id']);
        }
        if (isset($data['user_id'])) {
            $this->tenantAccess->assertUser($request->user(), User::query()->findOrFail($data['user_id']));
        }
        $cost = OperationalCost::create($data + ['recorded_by' => $request->user()->id]);
        $this->audit->record('operational_cost.recorded', $cost, null, $data, $request->user()->id);

        return back()->with('ok', 'تم تسجيل التكلفة واحتسابها في الربحية.');
    }

    public function receipt(Request $request, Payment $payment): Response
    {
        abort_unless($payment->client_id === $request->user('client')->client_id, 404);
        $payment->load(['client', 'installment.contract']);
        // Same rule as the contract it pays: a restricted delegate never sees a
        // receipt of a site-less (or contract-less) document.
        abort_unless($request->user('client')->canAccessDocumentSites(
            $payment->installment?->contract?->sites()->pluck('sites.id') ?? collect(),
        ), 403);
        $html = view('reports.receipt', compact('payment'))->render();
        $pdf = new Mpdf(['mode' => 'utf-8', 'directionality' => 'rtl', 'default_font' => 'xbriyaz', 'useOTL' => 0xFF]);
        $pdf->WriteHTML($html);

        return response($pdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$payment->receipt_no.'.pdf"']);
    }
}

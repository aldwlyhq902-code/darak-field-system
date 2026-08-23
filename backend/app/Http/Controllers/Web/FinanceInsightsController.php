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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Mpdf\Mpdf;

class FinanceInsightsController extends Controller
{
    public function __construct(private readonly ProfitabilityService $profitability, private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $contractModels = Contract::with(['client', 'installments'])->whereIn('status', ['active', 'ended'])->get();
        $contractProfits = $this->profitability->forContracts($contractModels);
        $contracts = $contractModels->map(fn ($contract) => ['contract' => $contract, 'profit' => $contractProfits[$contract->id]]);
        $clientBalances = Client::query()
            ->leftJoin('contracts', 'contracts.client_id', '=', 'clients.id')
            ->leftJoin('contract_installments', 'contract_installments.contract_id', '=', 'contracts.id')
            ->select('clients.*')
            ->selectRaw('COALESCE(SUM(contract_installments.total_amount), 0) AS billed_total')
            ->selectRaw('COALESCE(SUM(contract_installments.paid_amount), 0) AS paid_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN contract_installments.due_on < ? AND contract_installments.status != 'paid' THEN contract_installments.total_amount - contract_installments.paid_amount ELSE 0 END), 0) AS overdue_total", [today()->toDateString()])
            ->groupBy('clients.id')
            ->get()
            ->map(fn ($client) => [
                'client' => $client,
                'billed' => (float) $client->billed_total,
                'paid' => (float) $client->paid_total,
                'overdue' => (float) $client->overdue_total,
            ]);

        $visitModels = Visit::with(['site.client', 'technician'])->where('state', Visit::STATE_COMPLETED)->latest('closed_at')->limit(40)->get();
        $visitProfits = $this->profitability->forVisitModels($visitModels);
        $technicianModels = User::where('role', User::ROLE_TECHNICIAN)->get();
        $technicianProfits = $this->profitability->forTechnicians($technicianModels, now()->startOfMonth(), now()->endOfMonth());

        return view('panel.finance', [
            'contracts' => $contracts, 'clientBalances' => $clientBalances,
            'visits' => $visitModels->map(fn ($visit) => ['visit' => $visit, 'profit' => $visitProfits[$visit->id]]),
            'technicians' => $technicianModels->map(fn ($user) => ['user' => $user, 'profit' => $technicianProfits[$user->id]]),
        ]);
    }

    public function cost(Request $request): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'exists:clients,id'], 'contract_id' => ['nullable', 'exists:contracts,id'], 'visit_id' => ['nullable', 'exists:visits,id'], 'user_id' => ['nullable', 'exists:users,id'], 'category' => ['required', 'in:travel,vehicle,tool,administration,other'], 'description' => ['required', 'string', 'max:190'], 'amount' => ['required', 'numeric', 'gt:0'], 'incurred_on' => ['required', 'date']]);
        $cost = OperationalCost::create($data + ['recorded_by' => $request->user()->id]);
        $this->audit->record('operational_cost.recorded', $cost, null, $data, $request->user()->id);

        return back()->with('ok', 'تم تسجيل التكلفة واحتسابها في الربحية.');
    }

    public function receipt(Request $request, Payment $payment): Response
    {
        abort_unless($payment->client_id === $request->user('client')->client_id, 404);
        $payment->load(['client', 'installment.contract']);
        $allowed = $request->user('client')->allowedSites()->pluck('sites.id');
        if ($allowed->isNotEmpty()) {
            $contractSites = $payment->installment?->contract?->sites()->pluck('sites.id') ?? collect();
            abort_if($contractSites->diff($allowed)->isNotEmpty(), 403);
        }
        $html = view('reports.receipt', compact('payment'))->render();
        $pdf = new Mpdf(['mode' => 'utf-8', 'directionality' => 'rtl', 'default_font' => 'xbriyaz', 'useOTL' => 0xFF]);
        $pdf->WriteHTML($html);

        return response($pdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$payment->receipt_no.'.pdf"']);
    }
}

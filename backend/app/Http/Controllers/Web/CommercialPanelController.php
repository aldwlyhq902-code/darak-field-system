<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Services\AuditLogger;
use App\Services\CommercialService;
use App\Services\QuotationPricingService;
use App\Support\BusinessReference;
use App\Support\ClientPicker;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class CommercialPanelController extends Controller
{
    public function __construct(
        private readonly CommercialService $commercial,
        private readonly AuditLogger $audit,
        private readonly QuotationPricingService $pricing,
    ) {}

    public function index(Request $request): View
    {
        $today = now()->startOfDay();
        $openInstallments = ContractInstallment::query()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total_amount');

        return view('panel.commercial', ClientPicker::options($request) + [
            'quotations' => Quotation::with(['client', 'convertedContract'])
                ->latest()->paginate(25, ['*'], 'quotations_page')->withQueryString(),
            'installments' => ContractInstallment::with('contract.client')
                ->orderByDesc('due_on')->orderByDesc('id')
                ->paginate(50, ['*'], 'installments_page')->withQueryString(),
            'payments' => Payment::with(['client', 'installment.contract'])
                ->latest('paid_on')->latest('id')
                ->paginate(30, ['*'], 'payments_page')->withQueryString(),
            'pendingQuotationCount' => Quotation::where('status', 'sent')->count(),
            'openInstallmentsAmount' => Money::of((clone $openInstallments)
                ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) AS aggregate')
                ->value('aggregate')),
            'overdueInstallmentsCount' => (clone $openInstallments)
                ->where(function ($query) use ($today): void {
                    $query->where('status', 'overdue')->orWhereDate('due_on', '<', $today);
                })->count(),
            'contractsEnding' => Contract::with('client')->where('status', 'active')
                ->whereBetween('ends_on', [$today, $today->copy()->addDays(60)])->orderBy('ends_on')
                ->paginate(20, ['*'], 'ending_page')->withQueryString(),
        ]);
    }

    public function storeQuotation(Request $request): RedirectResponse
    {
        $data = $this->quotationData($request);
        $quote = DB::transaction(function () use ($request, $data): Quotation {
            $series = (string) Str::uuid();
            $quote = Quotation::create($data + [
                'series_uuid' => $series,
                'version' => 1,
                'quote_no' => $this->nextQuoteNumber(1),
                'status' => Quotation::STATUS_DRAFT,
                'terms' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['terms_text'] ?? '') ?: []))),
                'created_by' => $request->user()->id,
            ]);
            $quote->sites()->sync($data['site_ids']);
            $this->pricing->requestIfNeeded($quote, $request->user()->id);

            return $quote;
        });

        return redirect()->route('panel.commercial')->with('ok', "أُنشئ العرض {$quote->quote_no} كمسودة.".$this->approvalNote($quote));
    }

    public function revise(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->converted_contract_id !== null, 422);
        $data = $this->quotationData($request);
        $next = DB::transaction(function () use ($request, $quotation, $data): Quotation {
            Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            Quotation::where('series_uuid', $quotation->series_uuid)->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $version = Quotation::where('series_uuid', $quotation->series_uuid)->max('version') + 1;
            $next = Quotation::create($data + [
                'series_uuid' => $quotation->series_uuid, 'version' => $version,
                'quote_no' => $this->nextQuoteNumber($version), 'status' => Quotation::STATUS_DRAFT,
                'terms' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['terms_text'] ?? '') ?: []))),
                'created_by' => $request->user()->id,
            ]);
            $next->sites()->sync($data['site_ids']);
            $this->pricing->requestIfNeeded($next, $request->user()->id);

            return $next;
        });

        return back()->with('ok', "أُنشئ الإصدار {$next->version} دون تعديل الإصدار السابق.".$this->approvalNote($next));
    }

    public function send(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($quotation->status === Quotation::STATUS_DRAFT, 422);
        try {
            $this->pricing->assertSendable($quotation, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $quotation->forceFill(['status' => Quotation::STATUS_SENT, 'sent_at' => now()])->save();
        $this->audit->record('quotation.sent', $quotation, null, ['actor_id' => $request->user()->id]);

        return back()->with('ok', 'أصبح العرض متاحاً لاعتماد العميل من البوابة.');
    }

    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        try {
            $contract = $this->commercial->convertQuotation($quotation, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->route('panel.client', $contract->client_id)->with('ok', "حُوّل العرض إلى العقد {$contract->contract_no} وأُنشئ جدول دفعاته.");
    }

    public function payment(Request $request, ContractInstallment $installment): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:bank_transfer,cash,card,online'],
            'reference' => ['nullable', 'string', 'max:96'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $payment = $this->commercial->recordPayment($installment, $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "سُجل سند القبض {$payment->receipt_no}.");
    }

    /** Owner-only: the receipt stays on record, the installment reopens, commissions inside the window are voided. */
    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->commercial->refundPayment($payment, $data['reason'], $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "سُجل استرداد سند القبض {$payment->receipt_no}.");
    }

    private function quotationData(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'], 'title' => ['required', 'string', 'max:190'],
            'package_code' => ['required', 'in:basic,comprehensive'], 'price_amount' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:1'], 'billing_cycle' => ['required', 'in:monthly,quarterly,upfront,custom'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:60'], 'starts_on' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'], 'service_window_start' => ['required'],
            'service_window_end' => ['required'], 'sla_minutes' => ['required', 'integer', 'min:30', 'max:2880'],
            'terms_text' => ['nullable', 'string', 'max:4000'], 'site_ids' => ['required', 'array', 'min:1'],
            'site_ids.*' => [Rule::exists('sites', 'id')->where('client_id', $request->input('client_id'))],
            'down_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_installments_text' => ['nullable', 'string', 'max:8000'],
        ]);
        // `exists` ignores the tenant scope; the scoped load is the authorization.
        Client::query()->findOrFail($data['client_id']);

        $data['down_payment_amount'] = Money::of($data['down_payment_amount'] ?? 0);
        $data['custom_installments'] = $this->parseCustomInstallments($data['custom_installments_text'] ?? null);

        if ($data['billing_cycle'] === 'custom' && $data['custom_installments'] === []) {
            throw ValidationException::withMessages(['custom_installments_text' => 'أدخل دفعة مخصصة واحدة على الأقل بصيغة التاريخ|المبلغ.']);
        }

        // The schedule must add up exactly to the contract value; a mismatch is
        // the user's to fix, never silently absorbed into the last installment.
        $total = in_array($data['billing_cycle'], ['monthly', 'quarterly'], true)
            ? Money::mul($data['price_amount'], (int) $data['duration_months'])
            : Money::of($data['price_amount']);
        if (Money::compare($data['down_payment_amount'], $total) > 0) {
            throw ValidationException::withMessages(['down_payment_amount' => 'الدفعة المقدمة تتجاوز قيمة العقد ('.Money::format($total).' ر.س قبل الضريبة).']);
        }
        if ($data['billing_cycle'] === 'custom') {
            $scheduled = Money::add($data['down_payment_amount'], Money::sum(array_column($data['custom_installments'], 'amount')));
            if (! Money::equals($scheduled, $total)) {
                throw ValidationException::withMessages(['custom_installments_text' => 'مجموع الدفعة المقدمة والدفعات المخصصة ('.Money::format($scheduled).') يجب أن يساوي قيمة العقد ('.Money::format($total).') قبل الضريبة.']);
            }
        }

        return $data;
    }

    private function parseCustomInstallments(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        return collect(preg_split('/\r?\n/', $text) ?: [])->filter()->map(function (string $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 2 || ! strtotime($parts[0]) || ! is_numeric($parts[1]) || ! Money::isPositive($parts[1])) {
                throw ValidationException::withMessages(['custom_installments_text' => "صيغة دفعة غير صحيحة: {$line}"]);
            }

            return ['due_on' => date('Y-m-d', strtotime($parts[0])), 'amount' => Money::of($parts[1])];
        })->values()->all();
    }

    private function approvalNote(Quotation $quotation): string
    {
        return $this->pricing->approvalReason($quotation) === null
            ? ''
            : ' سعره بانتظار الاعتماد المالي الثنائي قبل الإرسال للعميل.';
    }

    private function nextQuoteNumber(int $version): string
    {
        return BusinessReference::make('QT', true, 'V'.$version);
    }
}

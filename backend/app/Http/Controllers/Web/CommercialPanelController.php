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
use App\Support\BusinessReference;
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
    ) {}

    public function index(): View
    {
        $today = now()->startOfDay();

        return view('panel.commercial', [
            'clients' => Client::where('is_active', true)->with('sites')->orderBy('name')->get(),
            'quotations' => Quotation::with(['client', 'convertedContract'])->latest()->limit(40)->get(),
            'installments' => ContractInstallment::with('contract.client')->orderBy('due_on')->limit(100)->get(),
            'payments' => Payment::with(['client', 'installment.contract'])->latest('paid_on')->limit(30)->get(),
            'contractsEnding' => Contract::with('client')->where('status', 'active')
                ->whereBetween('ends_on', [$today, $today->copy()->addDays(60)])->orderBy('ends_on')->get(),
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

            return $quote;
        });

        return redirect()->route('panel.commercial')->with('ok', "أُنشئ العرض {$quote->quote_no} كمسودة.");
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

            return $next;
        });

        return back()->with('ok', "أُنشئ الإصدار {$next->version} دون تعديل الإصدار السابق.");
    }

    public function send(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($quotation->status === Quotation::STATUS_DRAFT, 422);
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

        $data['down_payment_amount'] = (float) ($data['down_payment_amount'] ?? 0);
        $data['custom_installments'] = $this->parseCustomInstallments($data['custom_installments_text'] ?? null);

        if ($data['billing_cycle'] === 'custom' && $data['custom_installments'] === []) {
            throw ValidationException::withMessages(['custom_installments_text' => 'أدخل دفعة مخصصة واحدة على الأقل بصيغة التاريخ|المبلغ.']);
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
            if (count($parts) !== 2 || ! strtotime($parts[0]) || ! is_numeric($parts[1]) || (float) $parts[1] <= 0) {
                throw ValidationException::withMessages(['custom_installments_text' => "صيغة دفعة غير صحيحة: {$line}"]);
            }

            return ['due_on' => date('Y-m-d', strtotime($parts[0])), 'amount' => round((float) $parts[1], 2)];
        })->values()->all();
    }

    private function nextQuoteNumber(int $version): string
    {
        return BusinessReference::make('QT', true, 'V'.$version);
    }
}

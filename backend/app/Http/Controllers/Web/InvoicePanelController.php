<?php

namespace App\Http\Controllers\Web;

use App\Contracts\InvoiceProvider;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ExternalDocument;
use App\Models\Visit;
use App\Services\InvoiceService;
use App\Services\Invoicing\ManualInvoiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Invoices and credit notes known to Darak. With the manual provider this is
 * the finance team's task list: each pending document is issued in the
 * external (ZATCA-compliant) tool, then its number/date/QR are recorded here.
 */
class InvoicePanelController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(): View
    {
        return view('panel.invoices', [
            'provider' => app(InvoiceProvider::class)->name(),
            'pending' => $this->visibleDocuments()
                ->with(['client', 'parent'])
                ->where('status', ManualInvoiceProvider::STATUS_PENDING)
                ->orderBy('id')
                ->paginate(25, ['*'], 'pending_page')->withQueryString(),
            'pendingCount' => $this->visibleDocuments()->where('status', ManualInvoiceProvider::STATUS_PENDING)->count(),
            'documents' => $this->visibleDocuments()
                ->with(['client', 'parent'])
                ->where('status', '!=', ManualInvoiceProvider::STATUS_PENDING)
                ->latest('id')
                ->paginate(30, ['*'], 'documents_page')->withQueryString(),
        ]);
    }

    public function record(Request $request, ExternalDocument $document): RedirectResponse
    {
        abort_unless($this->visibleDocuments()->whereKey($document->id)->exists(), 404);
        $data = $request->validate([
            'external_number' => ['required', 'string', 'max:64'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'qr_reference' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $document = $this->invoices->recordManualIssue($document, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "سُجل المستند الخارجي {$document->external_number} وأصبح صادرًا غير قابل للتعديل.");
    }

    /**
     * external_documents has no tenant column of its own; it is visible exactly
     * when its client — or, for client-less documents, its visit — is visible
     * through the operating-branch scope.
     */
    private function visibleDocuments(): Builder
    {
        return ExternalDocument::query()->where(function (Builder $visible): void {
            $visible->whereIn('client_id', Client::query()->select('clients.id'))
                ->orWhere(fn (Builder $orphan) => $orphan->whereNull('client_id')
                    ->whereIn('visit_id', Visit::query()->select('visits.id')));
        });
    }
}

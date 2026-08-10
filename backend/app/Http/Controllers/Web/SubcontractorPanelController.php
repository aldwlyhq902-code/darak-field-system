<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\SubcontractorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SubcontractorPanelController extends Controller
{
    public function __construct(private readonly SubcontractorService $service)
    {
    }

    public function index(): View
    {
        return view('panel.subcontractors', [
            'partners' => Subcontractor::withCount('orders')->orderBy('name')->get(),
            'orders' => SubcontractorOrder::with(['subcontractor', 'workOrder.client', 'visit'])
                ->latest('id')->limit(50)->get(),
            'openWorkOrders' => WorkOrder::with('client')
                ->whereIn('status', ['open', 'scheduled', 'in_progress'])
                ->latest('id')->limit(80)->get(),
        ]);
    }

    public function storePartner(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'cr_number' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'specialties' => ['nullable', 'array'],
            'issues_official_certificates' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        Subcontractor::create($data + [
            'specialties' => $data['specialties'] ?? [],
            'issues_official_certificates' => $request->boolean('issues_official_certificates'),
            'is_active' => true,
        ]);

        return back()->with('ok', 'أُضيف شريك الباطن.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subcontractor_id' => ['required', 'exists:subcontractors,id'],
            'work_order_id' => ['required', 'exists:work_orders,id'],
            'visit_id' => ['nullable', 'exists:visits,id'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'scheduled_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm_negative_margin' => ['nullable', 'boolean'],
        ]);

        $preview = $this->service->preview((float) $data['purchase_cost'], (float) $data['sale_price']);

        // A loss-making assignment is possible but never accidental — it takes a
        // deliberate second confirmation.
        if ($preview['margin'] < 0 && ! $request->boolean('confirm_negative_margin')) {
            return back()->with('err', $preview['warning'] . ' فعّل تأكيد الهامش السالب إن كنت متعمداً.');
        }

        try {
            $order = $this->service->assign(
                Subcontractor::findOrFail($data['subcontractor_id']),
                WorkOrder::findOrFail($data['work_order_id']),
                (float) $data['purchase_cost'],
                (float) $data['sale_price'],
                // A nullable rule that is not submitted leaves the key absent
                // entirely, not present-and-null.
                isset($data['visit_id']) ? Visit::find($data['visit_id']) : null,
                $data['scheduled_for'] ?? null,
                $data['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        $margin = number_format($order->margin(), 2);
        $percent = $order->marginPercent();

        return back()->with('ok', "أُسند {$order->order_no} — الهامش {$margin} ريال ({$percent}%).");
    }

    public function uploadDocument(Request $request, SubcontractorOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'title' => ['required', 'string', 'max:190'],
            'issued_by' => ['required', 'string', 'max:190'],
            'issued_on' => ['nullable', 'date'],
        ]);

        $path = $request->file('file')->store("subcontractor-docs/{$order->id}", 'local');

        $this->service->attachDocument(
            $order,
            $path,
            $data['title'],
            $data['issued_by'],
            $data['issued_on'] ?? null,
        );

        return back()->with('ok', "أُرفق المستند باسم الجهة المُصدِرة: {$data['issued_by']}.");
    }

    public function download(SubcontractorOrder $order, int $index)
    {
        $documents = $order->documents ?? [];

        abort_unless(isset($documents[$index]), 404);

        $path = $documents[$index]['path'];

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function markDelivered(SubcontractorOrder $order): RedirectResponse
    {
        $this->service->markDelivered($order);

        return back()->with('ok', 'وُسم الأمر كمُنفَّذ.');
    }

    public function cancel(Request $request, SubcontractorOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        $this->service->cancel($order, $data['reason']);

        return back()->with('ok', 'أُلغي الأمر ولم تعد تكلفته تُحتسب.');
    }
}

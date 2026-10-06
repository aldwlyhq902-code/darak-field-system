<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\SubcontractorService;
use App\Support\Money;
use App\Support\PrivateFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubcontractorPanelController extends Controller
{
    public function __construct(private readonly SubcontractorService $service) {}

    public function index(): View
    {
        return view('panel.subcontractors', [
            'partners' => Subcontractor::withCount('orders')->orderBy('name')->paginate(30, ['*'], 'partners_page')->withQueryString(),
            // The assignment select needs every ACTIVE partner (a bounded reference set).
            'activePartners' => Subcontractor::where('is_active', true)->orderBy('name')->get(['id', 'name', 'specialties']),
            'orders' => SubcontractorOrder::with(['subcontractor', 'workOrder.client', 'visit'])
                ->latest('id')->paginate(25, ['*'], 'orders_page')->withQueryString(),
            // Work orders are referenced by number rather than a select capped at 80.
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
            'work_order_id' => ['nullable', 'required_without:wo_number', 'exists:work_orders,id'],
            'wo_number' => ['nullable', 'required_without:work_order_id', 'string', 'max:64'],
            'visit_id' => ['nullable', 'exists:visits,id'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'scheduled_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm_negative_margin' => ['nullable', 'boolean'],
        ]);

        $preview = $this->service->preview($data['purchase_cost'], $data['sale_price']);

        // A loss-making assignment is possible but never accidental — it takes a
        // deliberate second confirmation.
        if (Money::isNegative($preview['margin']) && ! $request->boolean('confirm_negative_margin')) {
            return back()->with('err', $preview['warning'].' فعّل تأكيد الهامش السالب إن كنت متعمداً.');
        }

        $workOrder = filled($data['wo_number'] ?? null)
            ? WorkOrder::query()->where('wo_number', trim($data['wo_number']))->firstOrFail()
            : WorkOrder::query()->findOrFail($data['work_order_id']);
        // Scoped load, and the visit must belong to the order it is subcontracted under.
        $visit = isset($data['visit_id']) ? Visit::query()->findOrFail($data['visit_id']) : null;
        abort_if($visit !== null && $visit->work_order_id !== $workOrder->id, 422, 'الزيارة لا تتبع أمر العمل المحدد.');

        try {
            $order = $this->service->assign(
                Subcontractor::findOrFail($data['subcontractor_id']),
                $workOrder,
                $data['purchase_cost'],
                $data['sale_price'],
                // A nullable rule that is not submitted leaves the key absent
                // entirely, not present-and-null.
                $visit,
                $data['scheduled_for'] ?? null,
                $data['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        $margin = Money::format($order->margin());
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

        $path = $request->file('file')->store("subcontractor-docs/{$order->id}", PrivateFiles::diskName());

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

        abort_unless(PrivateFiles::disk()->exists($path), 404);

        return PrivateFiles::disk()->download($path);
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

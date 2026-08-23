<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReplenishmentRequest;
use App\Models\RequestForQuotation;
use App\Models\StockLocation;
use App\Models\StockReservation;
use App\Models\StocktakeSession;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\VehicleStockTransfer;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\ProcurementService;
use App\Services\ReplenishmentService;
use App\Services\RfqService;
use App\Services\StocktakeService;
use App\Services\VehicleLoadSuggestionService;
use App\Support\BusinessReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class ProcurementPanelController extends Controller
{
    public function __construct(
        private readonly ProcurementService $service,
        private readonly AuditLogger $audit,
        private readonly ReplenishmentService $replenishment,
        private readonly StocktakeService $stocktakes,
        private readonly VehicleLoadSuggestionService $loadSuggestions,
        private readonly RfqService $rfqs,
    ) {}

    public function index(): View
    {
        return view('panel.procurement', [
            'suppliers' => Supplier::with('parts')->orderBy('name')->get(), 'parts' => Part::where('is_active', true)->orderBy('name')->get(),
            'locations' => StockLocation::with('vehicle.assignedUser')->where('is_active', true)->get(),
            'orders' => PurchaseOrder::with(['supplier', 'destination', 'items.part'])->latest()->limit(40)->get(),
            'transfers' => VehicleStockTransfer::with(['part', 'fromLocation.vehicle.assignedUser', 'toLocation.vehicle.assignedUser'])->latest()->limit(40)->get(),
            'reservations' => StockReservation::with(['visit.site.client', 'part', 'location'])->where('status', 'reserved')->latest()->limit(40)->get(),
            'visits' => Visit::with('site.client')->where('state', 'scheduled')->where('scheduled_start', '>=', now()->subDay())->orderBy('scheduled_start')->limit(100)->get(),
            'replenishments' => ReplenishmentRequest::with(['part', 'location', 'supplier'])->where('status', 'open')->latest()->get(),
            'lots' => InventoryLot::with(['part', 'supplier', 'location'])->where('qty_remaining', '>', 0)->latest()->limit(50)->get(),
            'stocktakes' => StocktakeSession::with(['location', 'assignedUser', 'lines'])->latest()->limit(30)->get(),
            'loadSuggestions' => $this->loadSuggestions->forTomorrow(),
            'supplierComparisons' => Part::with('suppliers')->whereHas('suppliers')->get()->map(fn ($part) => [
                'part' => $part,
                'offers' => $part->suppliers->sortBy(fn ($supplier) => (float) $supplier->pivot->last_price)->values(),
            ]),
            'rfqs' => RequestForQuotation::with(['destination', 'items.part', 'supplierQuotations.supplier', 'supplierQuotations.items.part', 'awardedQuotation.supplier'])->latest()->limit(30)->get(),
        ]);
    }

    public function createRfq(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'destination_location_id' => ['required', 'exists:stock_locations,id'], 'response_due_on' => ['required', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.part_id' => ['required', 'distinct', 'exists:parts,id'], 'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.specification' => ['nullable', 'string', 'max:1000'],
        ]);
        $rfq = $this->rfqs->create($data, $request->user()->id);

        return back()->with('ok', "أُرسل طلب الأسعار {$rfq->rfq_no} وأصبح جاهزًا لاستقبال عروض الموردين.");
    }

    public function supplierQuotation(Request $request, RequestForQuotation $rfq): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'supplier_reference' => ['nullable', 'string', 'max:96'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'], 'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.part_id' => ['required', 'distinct', 'exists:parts,id'], 'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        try {
            $this->rfqs->recordSupplierQuotation($rfq, $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'سُجل عرض المورد وأُعيد ترتيب المقارنة حسب الإجمالي ومدة التوريد.');
    }

    public function awardRfq(Request $request, RequestForQuotation $rfq, SupplierQuotation $quote): RedirectResponse
    {
        try {
            $order = $this->rfqs->award($rfq, $quote, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "تمت الترسية وتحويل العرض إلى أمر الشراء {$order->po_number}.");
    }

    public function supplier(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'vat_number' => ['nullable', 'string', 'max:32'], 'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email'], 'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'], 'notes' => ['nullable', 'string', 'max:1000']]);
        Supplier::create($data + ['is_active' => true]);

        return back()->with('ok', 'أُضيف المورد.');
    }

    public function supplierPart(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate(['part_id' => ['required', 'exists:parts,id'], 'supplier_sku' => ['nullable', 'string', 'max:64'], 'last_price' => ['required', 'numeric', 'min:0'], 'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'is_preferred' => ['nullable', 'boolean']]);
        $supplier->parts()->syncWithoutDetaching([$data['part_id'] => ['supplier_sku' => $data['supplier_sku'] ?? null, 'last_price' => $data['last_price'], 'lead_time_days' => $data['lead_time_days'] ?? null, 'is_preferred' => $request->boolean('is_preferred')]]);

        return back()->with('ok', 'رُبط سعر المورد بالصنف.');
    }

    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'exists:suppliers,id'], 'destination_location_id' => ['required', 'exists:stock_locations,id'], 'expected_on' => ['nullable', 'date', 'after_or_equal:today'], 'note' => ['nullable', 'string', 'max:1000'], 'part_id' => ['required', 'exists:parts,id'], 'qty' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['required', 'numeric', 'min:0']]);
        abort_unless(Supplier::findOrFail($data['supplier_id'])->is_active, 422);
        abort_unless(StockLocation::findOrFail($data['destination_location_id'])->type === StockLocation::TYPE_WAREHOUSE, 422);
        $order = DB::transaction(function () use ($data, $request) {
            $subtotal = round($data['qty'] * $data['unit_cost'], 2);
            $vat = round($subtotal * .15, 2);
            $order = PurchaseOrder::create(['po_number' => BusinessReference::make('PO'), 'supplier_id' => $data['supplier_id'], 'destination_location_id' => $data['destination_location_id'], 'ordered_on' => now(), 'expected_on' => $data['expected_on'] ?? null, 'status' => 'ordered', 'subtotal' => $subtotal, 'vat_amount' => $vat, 'total_amount' => $subtotal + $vat, 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);
            $order->items()->create(['part_id' => $data['part_id'], 'qty_ordered' => $data['qty'], 'unit_cost' => $data['unit_cost']]);
            $this->audit->record('purchase.ordered', $order, null, $order->only(['supplier_id', 'total_amount']), $request->user()->id);

            return $order;
        });

        return back()->with('ok', "أُنشئ أمر الشراء {$order->po_number}.");
    }

    public function receive(Request $request, PurchaseOrderItem $item): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'], 'lot_number' => ['nullable', 'string', 'max:96'],
            'serial_number' => ['nullable', 'string', 'max:96', 'unique:inventory_lots,serial_number'],
            'manufactured_on' => ['nullable', 'date'], 'warranty_until' => ['nullable', 'date'],
        ]);
        try {
            $this->service->receivePurchaseItem($item, (float) $data['qty'], $request->user()->id, $data);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'سُجل الاستلام وأضيف للمخزون.');
    }

    public function reserve(Request $request, Visit $visit): RedirectResponse
    {
        abort_unless($visit->state === Visit::STATE_SCHEDULED, 422);
        $data = $request->validate(['part_id' => ['required', 'exists:parts,id'], 'stock_location_id' => ['required', 'exists:stock_locations,id'], 'qty' => ['required', 'numeric', 'gt:0']]);
        try {
            $this->service->reserve($visit, $data['part_id'], $data['stock_location_id'], (float) $data['qty'], $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'حُجزت القطعة للزيارة ولن تظهر كمتاحة لغيرها.');
    }

    public function release(Request $request, StockReservation $reservation): RedirectResponse
    {
        $reservation->forceFill(['status' => 'released'])->save();
        $this->audit->record('stock.released', $reservation, null, null, $request->user()->id);

        return back()->with('ok', 'أُلغي الحجز.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate(['part_id' => ['required', 'exists:parts,id'], 'qty' => ['required', 'numeric', 'gt:0'], 'from_location_id' => ['required', 'exists:stock_locations,id'], 'to_location_id' => ['required', 'exists:stock_locations,id', 'different:from_location_id'], 'note' => ['nullable', 'string', 'max:500']]);
        abort_unless(StockLocation::findOrFail($data['from_location_id'])->type === StockLocation::TYPE_VEHICLE, 422);
        abort_unless(StockLocation::findOrFail($data['to_location_id'])->type === StockLocation::TYPE_VEHICLE, 422);
        $transfer = VehicleStockTransfer::create($data + ['transfer_no' => BusinessReference::make('VTR'), 'status' => 'pending_release', 'requested_by' => $request->user()->id]);

        return back()->with('ok', "أُنشئ التحويل {$transfer->transfer_no} وينتظر قبول المستلم.");
    }

    public function acceptTransfer(Request $request, VehicleStockTransfer $transfer): RedirectResponse
    {
        $transfer->loadMissing('toLocation.vehicle');
        $receiver = $transfer->toLocation?->vehicle?->assigned_user_id;
        abort_unless($request->user()->isOwner() || $receiver === $request->user()->id, 403);

        try {
            $this->service->acceptTransfer($transfer, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'قُبل التحويل وانتقلت الكمية فعلياً.');
    }

    public function releaseTransfer(Request $request, VehicleStockTransfer $transfer): RedirectResponse
    {
        $transfer->loadMissing('fromLocation.vehicle');
        $sender = $transfer->fromLocation?->vehicle?->assigned_user_id;
        abort_unless($request->user()->isOwner() || $sender === $request->user()->id, 403);
        try {
            $this->service->releaseTransfer($transfer, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'أكد المرسل التسليم وأصبح التحويل بانتظار المستلم.');
    }

    public function scanReplenishment(): RedirectResponse
    {
        $count = $this->replenishment->scan()->count();

        return back()->with('ok', "اكتمل فحص الحد الأدنى: {$count} طلب توريد جديد.");
    }

    public function createStocktake(Request $request): RedirectResponse
    {
        $data = $request->validate(['stock_location_id' => ['required', 'exists:stock_locations,id'], 'assigned_user_id' => ['nullable', 'exists:users,id']]);
        try {
            $this->stocktakes->create((int) $data['stock_location_id'], isset($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'فُتحت جلسة الجرد وأصبحت متاحة من جوال الفني المكلّف.');
    }

    public function completeStocktake(Request $request, StocktakeSession $session): RedirectResponse
    {
        try {
            $this->stocktakes->complete($session, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'أُقفل الجرد وسُجلت فروقات المخزون بحركات تدقيق مستقلة.');
    }
}

<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\PurchaseOrderItem;
use App\Models\StockReservation;
use App\Models\VehicleStockTransfer;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProcurementService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    public function reserve(Visit $visit, int $partId, int $locationId, float $qty, int $actorId): StockReservation
    {
        return DB::transaction(function () use ($visit, $partId, $locationId, $qty, $actorId) {
            $available = $this->inventory->availableBalance($partId, $locationId, $visit->id);
            if ($qty <= 0 || $qty > $available) {
                throw new RuntimeException("الكمية المتاحة بعد الحجوزات {$available} فقط.");
            }
            $reservation = StockReservation::updateOrCreate(['visit_id' => $visit->id, 'part_id' => $partId], [
                'stock_location_id' => $locationId, 'qty' => $qty, 'status' => 'reserved', 'expires_at' => $visit->scheduled_end?->copy()->addDay(), 'created_by' => $actorId,
            ]);
            $this->audit->record('stock.reserved', $reservation, null, $reservation->only(['visit_id', 'part_id', 'qty', 'stock_location_id']), $actorId);

            return $reservation;
        });
    }

    /** @param array<string, mixed> $tracking */
    public function receivePurchaseItem(PurchaseOrderItem $item, float $qty, int $actorId, array $tracking = []): void
    {
        DB::transaction(function () use ($item, $qty, $actorId, $tracking) {
            $item = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($qty <= 0 || $qty > $item->remaining()) {
                throw new RuntimeException('كمية الاستلام تتجاوز المتبقي في أمر الشراء.');
            }

            $part = $item->part;
            $hasTracking = $part->critical_tracking || filled($tracking['lot_number'] ?? null) || filled($tracking['serial_number'] ?? null);
            if ($part->critical_tracking && ! filled($tracking['lot_number'] ?? null) && ! filled($tracking['serial_number'] ?? null)) {
                throw new RuntimeException('هذا الصنف مهم ويتطلب رقم دفعة أو رقمًا تسلسليًا عند الاستلام.');
            }
            if (filled($tracking['serial_number'] ?? null) && abs($qty - 1) > .0005) {
                throw new RuntimeException('الرقم التسلسلي يمثل قطعة واحدة فقط؛ استلم كل رقم تسلسلي في عملية مستقلة.');
            }

            $lot = $hasTracking ? InventoryLot::create([
                'part_id' => $item->part_id,
                'supplier_id' => $item->purchaseOrder->supplier_id,
                'purchase_order_item_id' => $item->id,
                'stock_location_id' => $item->purchaseOrder->destination_location_id,
                'lot_number' => $tracking['lot_number'] ?? null,
                'serial_number' => $tracking['serial_number'] ?? null,
                'manufactured_on' => $tracking['manufactured_on'] ?? null,
                'warranty_until' => $tracking['warranty_until'] ?? ($part->default_warranty_months ? now()->addMonths($part->default_warranty_months)->toDateString() : null),
                'qty_received' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => $item->unit_cost,
            ]) : null;

            $this->inventory->receipt((string) Str::uuid(), $item->part_id, $qty, $item->purchaseOrder->destination_location_id, [
                'user_id' => $actorId, 'unit_cost' => $item->unit_cost, 'note' => 'استلام '.$item->purchaseOrder->po_number,
                'inventory_lot_id' => $lot?->id,
            ]);
            $item->increment('qty_received', $qty);
            $order = $item->purchaseOrder->fresh('items');
            $complete = $order->items->every(fn ($row) => $row->remaining() < .0005);
            $order->forceFill(['status' => $complete ? 'received' : 'partially_received'])->save();
            $item->part->forceFill(['purchase_cost' => $item->unit_cost])->save();
            $this->audit->record('purchase.received', $order, null, ['item_id' => $item->id, 'qty' => $qty], $actorId);
        });
    }

    public function acceptTransfer(VehicleStockTransfer $transfer, int $actorId): void
    {
        DB::transaction(function () use ($transfer, $actorId) {
            $transfer = VehicleStockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($transfer->status, ['pending', 'pending_receive'], true)) {
                throw new RuntimeException('هذا التحويل عولج سابقاً.');
            }
            $this->inventory->transferVehicleStock((string) Str::uuid(), $transfer->part_id, (float) $transfer->qty, $transfer->from_location_id, $transfer->to_location_id, ['user_id' => $actorId, 'note' => $transfer->transfer_no]);
            $transfer->forceFill(['status' => 'accepted', 'accepted_by' => $actorId, 'accepted_at' => now()])->save();
            $this->audit->record('vehicle_stock.accepted', $transfer, null, ['actor_id' => $actorId], $actorId);
        });
    }

    public function releaseTransfer(VehicleStockTransfer $transfer, int $actorId): void
    {
        DB::transaction(function () use ($transfer, $actorId) {
            $transfer = VehicleStockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'pending_release') {
                throw new RuntimeException('هذا التحويل لا ينتظر تسليم المرسل.');
            }
            if ($this->inventory->availableBalance($transfer->part_id, $transfer->from_location_id) + .0005 < (float) $transfer->qty) {
                throw new RuntimeException('الرصيد المتاح في سيارة المرسل لا يكفي للتحويل.');
            }
            $transfer->forceFill(['status' => 'pending_receive', 'released_by' => $actorId, 'released_at' => now()])->save();
            $this->audit->record('vehicle_stock.released', $transfer, null, ['actor_id' => $actorId], $actorId);
        });
    }
}

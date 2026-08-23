<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\RequestForQuotation;
use App\Models\SupplierQuotation;
use App\Support\BusinessReference;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RfqService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data, int $actorId): RequestForQuotation
    {
        return DB::transaction(function () use ($data, $actorId): RequestForQuotation {
            $rfq = RequestForQuotation::create([
                'rfq_no' => BusinessReference::make('RFQ'),
                'destination_location_id' => $data['destination_location_id'], 'response_due_on' => $data['response_due_on'],
                'status' => 'sent', 'note' => $data['note'] ?? null, 'created_by' => $actorId, 'sent_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                $rfq->items()->create($item);
            }
            $this->audit->record('rfq.sent', $rfq, null, ['items' => $data['items']], $actorId);

            return $rfq;
        });
    }

    public function recordSupplierQuotation(RequestForQuotation $rfq, array $data, int $actorId): SupplierQuotation
    {
        if (! in_array($rfq->status, ['sent', 'quotes_received'], true)) {
            throw new RuntimeException('لا يمكن إضافة عرض مورد إلى طلب مغلق.');
        }

        return DB::transaction(function () use ($rfq, $data, $actorId): SupplierQuotation {
            $requested = $rfq->items()->pluck('qty', 'part_id');
            foreach ($data['items'] as $item) {
                if (! $requested->has($item['part_id'])) {
                    throw new RuntimeException('العرض يتضمن صنفًا غير موجود في طلب الأسعار.');
                }
            }
            $subtotal = round((float) collect($data['items'])->sum(fn ($item) => (float) $item['qty'] * (float) $item['unit_cost']), 2);
            $vat = round($subtotal * .15, 2);
            $quote = SupplierQuotation::updateOrCreate(
                ['request_for_quotation_id' => $rfq->id, 'supplier_id' => $data['supplier_id']],
                ['supplier_reference' => $data['supplier_reference'] ?? null, 'valid_until' => $data['valid_until'] ?? null,
                    'lead_time_days' => $data['lead_time_days'], 'subtotal' => $subtotal, 'vat_amount' => $vat,
                    'total_amount' => $subtotal + $vat, 'status' => 'received', 'note' => $data['note'] ?? null, 'recorded_by' => $actorId],
            );
            $quote->items()->delete();
            foreach ($data['items'] as $item) {
                $quote->items()->create($item);
            }
            $rfq->forceFill(['status' => 'quotes_received'])->save();
            $this->audit->record('rfq.supplier_quote_recorded', $quote, null, ['rfq_id' => $rfq->id, 'total' => $subtotal + $vat], $actorId);

            return $quote;
        });
    }

    public function award(RequestForQuotation $rfq, SupplierQuotation $quote, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($rfq, $quote, $actorId): PurchaseOrder {
            $locked = RequestForQuotation::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($locked->purchase_order_id !== null || $locked->status === 'awarded') {
                throw new RuntimeException('سبق ترسية طلب الأسعار.');
            }
            if ($quote->request_for_quotation_id !== $locked->id || $quote->status !== 'received') {
                throw new RuntimeException('عرض المورد لا يتبع طلب الأسعار أو لم يعد صالحًا.');
            }
            $quote->loadMissing('items');
            $order = PurchaseOrder::create([
                'po_number' => BusinessReference::make('PO'),
                'supplier_id' => $quote->supplier_id, 'destination_location_id' => $locked->destination_location_id,
                'ordered_on' => now(), 'expected_on' => now()->addDays($quote->lead_time_days), 'status' => 'ordered',
                'subtotal' => $quote->subtotal, 'vat_amount' => $quote->vat_amount, 'total_amount' => $quote->total_amount,
                'note' => 'ناتج عن ترسية '.$locked->rfq_no, 'created_by' => $actorId,
            ]);
            foreach ($quote->items as $item) {
                $order->items()->create(['part_id' => $item->part_id, 'qty_ordered' => $item->qty, 'unit_cost' => $item->unit_cost]);
            }
            SupplierQuotation::where('request_for_quotation_id', $locked->id)->update(['status' => 'not_selected']);
            $quote->forceFill(['status' => 'awarded'])->save();
            $locked->forceFill(['status' => 'awarded', 'awarded_supplier_quotation_id' => $quote->id, 'purchase_order_id' => $order->id])->save();
            $this->audit->record('rfq.awarded', $locked, null, ['supplier_quotation_id' => $quote->id, 'purchase_order_id' => $order->id], $actorId);

            return $order;
        });
    }
}

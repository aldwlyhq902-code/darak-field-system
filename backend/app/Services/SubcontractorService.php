<?php

namespace App\Services;

use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hood cleaning, gas work and pipe extensions all run through partners, and the
 * whole phase-1 field test is 100% subcontracted. Without this the partner's cost
 * is invisible and every margin figure in the system is wrong by that amount.
 *
 * MVP scope is deliberately vendor + cost + documents + visit link. Partner
 * scoring and smart capacity coverage are backlog.
 */
class SubcontractorService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * The margin is computed and shown BEFORE the order is confirmed. Discovering
     * after the fact that a partner charges more than the client pays is exactly
     * the failure this prevents.
     *
     * @return array{purchase_cost: float, sale_price: float, margin: float, margin_percent: ?float, warning: ?string}
     */
    public function preview(float $purchaseCost, float $salePrice): array
    {
        $margin = round($salePrice - $purchaseCost, 2);
        $percent = $salePrice > 0 ? round($margin / $salePrice * 100, 1) : null;

        $warning = match (true) {
            $margin < 0 => 'هذا الأمر يخسر: تكلفة الشريك أعلى من سعر البيع.',
            $percent !== null && $percent < 20 => 'الهامش أقل من 20% — راجع السعر قبل التأكيد.',
            default => null,
        };

        return [
            'purchase_cost' => $purchaseCost,
            'sale_price' => $salePrice,
            'margin' => $margin,
            'margin_percent' => $percent,
            'warning' => $warning,
        ];
    }

    public function assign(
        Subcontractor $partner,
        WorkOrder $workOrder,
        float $purchaseCost,
        float $salePrice,
        ?Visit $visit = null,
        ?string $scheduledFor = null,
        ?string $note = null,
    ): SubcontractorOrder {
        if (! $partner->is_active) {
            throw new RuntimeException('هذا الشريك غير نشط.');
        }

        if ($purchaseCost < 0 || $salePrice < 0) {
            throw new RuntimeException('القيم لا يمكن أن تكون سالبة.');
        }

        return DB::transaction(function () use ($partner, $workOrder, $purchaseCost, $salePrice, $visit, $scheduledFor, $note) {
            $order = SubcontractorOrder::create([
                'subcontractor_id' => $partner->id,
                'work_order_id' => $workOrder->id,
                'visit_id' => $visit?->id,
                // Short placeholder; the real number comes from the row's own id
                // below. max(id)+1 collides under concurrent assigns, and a full
                // uuid here overflowed the column.
                'order_no' => 'TMP-' . Str::random(12),
                'purchase_cost' => $purchaseCost,
                'sale_price' => $salePrice,
                'status' => 'assigned',
                'scheduled_for' => $scheduledFor,
                'note' => $note,
            ]);

            // Derived from the row's own id inside the same transaction, so two
            // concurrent assigns cannot produce the same number.
            $order->forceFill([
                'order_no' => 'SUB-' . str_pad((string) $order->id, 5, '0', STR_PAD_LEFT),
            ])->save();

            $this->audit->record('subcontractor.assigned', $order, null, [
                'partner' => $partner->name,
                'work_order' => $workOrder->wo_number,
                'purchase_cost' => $purchaseCost,
                'sale_price' => $salePrice,
                'margin' => $order->margin(),
            ]);

            return $order;
        });
    }

    /**
     * Partner documents are stored under the ISSUING party's name. Darak
     * coordinates accredited parties; it does not accredit, and a certificate
     * filed as if Darak issued it would be a real misrepresentation.
     */
    public function attachDocument(
        SubcontractorOrder $order,
        string $path,
        string $title,
        string $issuedBy,
        ?string $issuedOn = null,
    ): SubcontractorOrder {
        $documents = $order->documents ?? [];

        $documents[] = [
            'path' => $path,
            'title' => $title,
            'issued_by' => $issuedBy,
            'issued_on' => $issuedOn,
            'uploaded_at' => now()->toIso8601String(),
        ];

        $order->forceFill(['documents' => $documents])->save();

        $this->audit->record('subcontractor.document', $order, null, [
            'title' => $title,
            'issued_by' => $issuedBy,
        ]);

        return $order->refresh();
    }

    public function markDelivered(SubcontractorOrder $order): SubcontractorOrder
    {
        $order->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
        ])->save();

        return $order->refresh();
    }

    public function cancel(SubcontractorOrder $order, string $reason): SubcontractorOrder
    {
        $order->forceFill([
            'status' => 'cancelled',
            'note' => trim(($order->note ?? '') . "\nألغي: {$reason}"),
        ])->save();

        $this->audit->record('subcontractor.cancelled', $order, null, ['reason' => $reason]);

        return $order->refresh();
    }

    /** Cancelled orders carry no cost — they never happened. */
    public function costForVisit(Visit $visit): float
    {
        return (float) SubcontractorOrder::where('visit_id', $visit->id)
            ->where('status', '!=', 'cancelled')
            ->sum('purchase_cost');
    }

    public function revenueForVisit(Visit $visit): float
    {
        return (float) SubcontractorOrder::where('visit_id', $visit->id)
            ->where('status', '!=', 'cancelled')
            ->sum('sale_price');
    }
}

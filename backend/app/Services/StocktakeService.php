<?php

namespace App\Services;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Part;
use App\Models\StocktakeSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class StocktakeService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    public function create(int $locationId, ?int $assignedUserId, int $actorId): StocktakeSession
    {
        return DB::transaction(function () use ($locationId, $assignedUserId, $actorId) {
            if (StocktakeSession::where('stock_location_id', $locationId)->where('status', 'open')->exists()) {
                throw new BusinessRuleViolation('STOCKTAKE_ALREADY_OPEN', 'يوجد جرد مفتوح لهذا الموقع بالفعل.');
            }
            $session = StocktakeSession::create([
                'public_reference' => (string) Str::uuid(), 'stock_location_id' => $locationId,
                'assigned_user_id' => $assignedUserId, 'status' => 'open', 'started_at' => now(), 'created_by' => $actorId,
            ]);
            foreach (Part::where('is_active', true)->get() as $part) {
                $expected = $this->inventory->balance($part->id, $locationId);
                $session->lines()->create(['part_id' => $part->id, 'expected_qty' => $expected, 'counted_qty' => 0, 'variance_qty' => -$expected]);
            }
            $this->audit->record('stocktake.opened', $session, null, ['location_id' => $locationId], $actorId);

            return $session->load('lines.part');
        });
    }

    public function scan(StocktakeSession $session, string $code, float $qty): StocktakeSession
    {
        if ($session->status !== 'open') {
            throw new BusinessRuleViolation('STOCKTAKE_CLOSED', 'جلسة الجرد مغلقة، فلا يمكن تسجيل كميات فيها.');
        }
        if ($qty < 0) {
            throw new BusinessRuleViolation('STOCKTAKE_INVALID_QUANTITY', 'الكمية المعدودة لا يمكن أن تكون سالبة.', 422);
        }
        // A damaged label or a code from outside the catalogue is something to
        // tell the technician, not a 404 that reads "the item was deleted".
        $part = Part::where('qr_code', $code)->orWhere('sku', $code)->first()
            ?? throw new BusinessRuleViolation('STOCKTAKE_UNKNOWN_CODE', 'لا يوجد صنف بهذا الرمز في الكتالوج. تأكد من الملصق.', 422);
        $expected = $this->inventory->balance($part->id, $session->stock_location_id);
        $session->lines()->updateOrCreate(['part_id' => $part->id], [
            'expected_qty' => $expected, 'counted_qty' => $qty,
            'variance_qty' => round($qty - $expected, 3), 'scan_code' => $code,
        ]);

        return $session->load('lines.part');
    }

    public function complete(StocktakeSession $session, int $actorId): StocktakeSession
    {
        return DB::transaction(function () use ($session, $actorId) {
            $session = StocktakeSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'open') {
                throw new BusinessRuleViolation('STOCKTAKE_ALREADY_COMPLETED', 'سبق إقفال جلسة الجرد.');
            }
            foreach ($session->lines as $line) {
                $variance = round((float) $line->counted_qty - $this->inventory->balance($line->part_id, $session->stock_location_id), 3);
                if (abs($variance) < .0005) {
                    continue;
                }
                $this->inventory->adjust(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, 'stocktake-'.$session->public_reference.'-'.$line->part_id)->toString(),
                    $line->part_id, abs($variance),
                    $variance < 0 ? $session->stock_location_id : null,
                    $variance > 0 ? $session->stock_location_id : null,
                    'تسوية جرد '.$session->public_reference,
                );
                $line->forceFill(['variance_qty' => $variance])->save();
            }
            $session->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $this->audit->record('stocktake.completed', $session, null, ['actor_id' => $actorId], $actorId);

            return $session->refresh()->load('lines.part');
        });
    }
}

<?php

namespace App\Services;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Part;
use App\Models\StocktakeLine;
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
            // A count waiting for review still owns its location: a second one
            // would be approved against balances the first is about to change.
            if (StocktakeSession::where('stock_location_id', $locationId)
                ->whereIn('status', [StocktakeSession::STATUS_OPEN, StocktakeSession::STATUS_SUBMITTED])->exists()) {
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
        if ($session->status !== StocktakeSession::STATUS_OPEN) {
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

    /**
     * The counter is done: the count goes to review. Nothing moves in stock —
     * completing on the phone used to post every variance as an adjustment
     * on the counter's word alone.
     */
    public function submit(StocktakeSession $session, int $actorId): StocktakeSession
    {
        return DB::transaction(function () use ($session, $actorId) {
            $session = StocktakeSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== StocktakeSession::STATUS_OPEN) {
                throw new BusinessRuleViolation('STOCKTAKE_ALREADY_COMPLETED', 'سبق إقفال جلسة الجرد.');
            }
            $session->forceFill([
                'status' => StocktakeSession::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $actorId,
            ])->save();
            $this->audit->record('stocktake.submitted', $session, null, [
                'actor_id' => $actorId,
                'scanned_lines' => $session->scannedLines()->count(),
            ], $actorId);

            return $session->refresh();
        });
    }

    /**
     * Expected (now) against counted, for the lines that were scanned. What the
     * reviewer approves; the phone never sees the expected side.
     *
     * @return array{lines: list<array{line: StocktakeLine, expected: float, counted: float, variance: float}>, unscanned: int}
     */
    public function variances(StocktakeSession $session): array
    {
        $lines = [];
        foreach ($session->scannedLines()->with('part')->get() as $line) {
            $expected = (float) $this->inventory->balance($line->part_id, $session->stock_location_id);
            $counted = (float) $line->counted_qty;
            $lines[] = ['line' => $line, 'expected' => $expected, 'counted' => $counted, 'variance' => round($counted - $expected, 3)];
        }

        return ['lines' => $lines, 'unscanned' => $session->lines()->whereNull('scan_code')->count()];
    }

    /**
     * Approves the count and posts its variances (back office). Only scanned
     * lines adjust: a part nobody scanned was not counted, and writing it down
     * to zero turned "forgot to scan" into a stock write-off. The approver must
     * not be the person who counted.
     */
    public function complete(StocktakeSession $session, int $actorId): StocktakeSession
    {
        return DB::transaction(function () use ($session, $actorId) {
            $session = StocktakeSession::lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, [StocktakeSession::STATUS_OPEN, StocktakeSession::STATUS_SUBMITTED], true)) {
                throw new BusinessRuleViolation('STOCKTAKE_ALREADY_COMPLETED', 'سبق إقفال جلسة الجرد.');
            }
            if ($session->submitted_by !== null && (int) $session->submitted_by === $actorId) {
                throw new BusinessRuleViolation('STOCKTAKE_SELF_APPROVAL', 'لا يعتمد الجرد من قام بالعد. يعتمده مستخدم آخر.');
            }
            foreach ($session->scannedLines as $line) {
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
            $session->forceFill([
                'status' => StocktakeSession::STATUS_COMPLETED,
                'completed_at' => now(),
                'approved_by' => $actorId,
            ])->save();
            $this->audit->record('stocktake.completed', $session, null, ['actor_id' => $actorId], $actorId);

            return $session->refresh()->load('lines.part');
        });
    }
}

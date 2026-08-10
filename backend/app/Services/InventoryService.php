<?php

namespace App\Services;

use App\Models\Part;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Five movement types only (PRD v1.2 §2). Transfer between vehicles, reservation
 * and advanced stocktake are backlog: they need offline conflict resolution that
 * can produce negative or duplicated balances, and that risk is not worth taking
 * before real operating data exists.
 *
 * Every movement carries a device-generated idempotency_key, so a part issued while
 * offline and replayed five times deducts stock exactly once.
 */
class InventoryService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Idempotent movement. Returns the existing move unchanged if the key was seen.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $idempotencyKey, array $attributes): StockMove
    {
        $existing = StockMove::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing; // replay — deliberately a no-op
        }

        try {
            return $this->insertMove($idempotencyKey, $attributes);
        } catch (QueryException $e) {
            // Two identical requests racing: the loser hits the unique index.
            // That is still a successful no-op, not a 500 — the whole point of an
            // idempotency key is that the caller may retry freely.
            if ($this->isUniqueViolation($e)) {
                $winner = StockMove::where('idempotency_key', $idempotencyKey)->first();

                if ($winner !== null) {
                    return $winner;
                }
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function insertMove(string $idempotencyKey, array $attributes): StockMove
    {
        return DB::transaction(function () use ($idempotencyKey, $attributes) {
            // Serialise check-and-insert per (part, location).
            //
            // The idempotency key protects a REPLAY of the same event. It does
            // nothing for two DIFFERENT events racing: under READ COMMITTED both
            // summed the pre-insert history, both saw enough stock, and both
            // inserted — driving the balance negative and invalidating every cost
            // figure built on those movements.
            $this->lockPartLocation(
                (int) $attributes['part_id'],
                (int) ($attributes['from_location_id'] ?? $attributes['to_location_id'] ?? 0),
            );

            $moveType = $attributes['move_type'];

            if (! in_array($moveType, StockMove::TYPES, true)) {
                throw new RuntimeException("Unknown movement type [{$moveType}].");
            }

            $part = Part::findOrFail($attributes['part_id']);
            $qty = round((float) $attributes['qty'], 3);

            if ($qty <= 0) {
                throw new RuntimeException('Movement quantity must be positive.');
            }

            if (! empty($attributes['from_location_id'])) {
                $this->assertSufficientStock(
                    (int) $attributes['part_id'],
                    (int) $attributes['from_location_id'],
                    $qty,
                    $moveType,
                );
            }

            // Inside the lock. Checking the returned total before acquiring it let
            // two concurrent returns read the same figure and both pass, together
            // returning more than was ever issued.
            if (isset($attributes['assert_within_issue'])) {
                $this->assertReturnWithinIssue($attributes['assert_within_issue'], $qty);
            }

            $move = StockMove::create([
                'move_type' => $moveType,
                'part_id' => $part->id,
                'qty' => $qty,
                'from_location_id' => $attributes['from_location_id'] ?? null,
                'to_location_id' => $attributes['to_location_id'] ?? null,
                'visit_id' => $attributes['visit_id'] ?? null,
                'user_id' => $attributes['user_id'] ?? auth()->id(),
                'device_id' => $attributes['device_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $attributes['reversal_of_id'] ?? null,
                'unit_cost' => $attributes['unit_cost'] ?? $part->purchase_cost,
                'device_timestamp' => $attributes['device_timestamp'] ?? null,
                'server_received_at' => CarbonImmutable::now(),
                'note' => $attributes['note'] ?? null,
                // 'assert_within_issue' is a guard directive, not a column.
            ]);

            $this->audit->record('inventory.move', $move, null, $move->only([
                'move_type', 'part_id', 'qty', 'from_location_id', 'to_location_id', 'visit_id',
            ]));

            return $move;
        });
    }

    /** Warehouse receipt from a supplier. */
    public function receipt(string $key, int $partId, float $qty, int $toLocationId, array $extra = []): StockMove
    {
        return $this->record($key, array_merge($extra, [
            'move_type' => StockMove::RECEIPT,
            'part_id' => $partId,
            'qty' => $qty,
            'to_location_id' => $toLocationId,
        ]));
    }

    /** Warehouse -> vehicle. */
    public function loadVehicle(string $key, int $partId, float $qty, int $fromLocationId, int $toLocationId, array $extra = []): StockMove
    {
        return $this->record($key, array_merge($extra, [
            'move_type' => StockMove::VEHICLE_LOAD,
            'part_id' => $partId,
            'qty' => $qty,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
        ]));
    }

    /** Vehicle -> visit (consumption). */
    public function issueToVisit(string $key, int $partId, float $qty, int $fromLocationId, Visit $visit, array $extra = []): StockMove
    {
        return $this->record($key, array_merge($extra, [
            'move_type' => StockMove::VISIT_ISSUE,
            'part_id' => $partId,
            'qty' => $qty,
            'from_location_id' => $fromLocationId,
            'visit_id' => $visit->id,
        ]));
    }

    /**
     * Visit -> vehicle (unused / faulty / wrong part).
     * Note: this corrects stock and cost. It does NOT touch an issued invoice —
     * that path goes through a credit note (see InvoiceService).
     */
    public function returnFromVisit(string $key, StockMove $original, float $qty, int $toLocationId, array $extra = []): StockMove
    {
        // Only an ISSUE can be reversed. Returning a RETURN builds a chain that
        // manufactures stock out of nothing, one hop at a time.
        if ($original->move_type !== StockMove::VISIT_ISSUE) {
            throw new RuntimeException('Only a part issued to a visit can be returned.');
        }

        return $this->record($key, array_merge($extra, [
            'move_type' => StockMove::VISIT_RETURN,
            'part_id' => $original->part_id,
            'qty' => $qty,
            'to_location_id' => $toLocationId,
            'visit_id' => $original->visit_id,
            'reversal_of_id' => $original->id,
            'unit_cost' => $original->unit_cost,
            // Checked inside the locked transaction, not before it.
            'assert_within_issue' => $original,
        ]));
    }

    /** Stocktake correction. */
    public function adjust(string $key, int $partId, float $qty, ?int $fromLocationId, ?int $toLocationId, string $note): StockMove
    {
        return $this->record($key, [
            'move_type' => StockMove::ADJUSTMENT,
            'part_id' => $partId,
            'qty' => $qty,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'note' => $note,
        ]);
    }

    /** Current balance of one part at one location. */
    public function balance(int $partId, int $locationId): float
    {
        $in = (float) StockMove::where('part_id', $partId)->where('to_location_id', $locationId)->sum('qty');
        $out = (float) StockMove::where('part_id', $partId)->where('from_location_id', $locationId)->sum('qty');

        return round($in - $out, 3);
    }

    /**
     * All balances at one location in ONE query.
     *
     * This used to loop every active part and run two SUMs each — 2N+1 queries
     * that degrade linearly as stock_moves grows, on a page the supervisor opens
     * constantly.
     *
     * @return array<int, array{part_id:int, sku:string, name:string, qty:float, reorder_level:float, below_reorder:bool}>
     */
    public function locationBalances(int $locationId): array
    {
        $totals = StockMove::query()
            ->selectRaw('part_id')
            ->selectRaw('SUM(CASE WHEN to_location_id = ? THEN qty ELSE 0 END) AS moved_in', [$locationId])
            ->selectRaw('SUM(CASE WHEN from_location_id = ? THEN qty ELSE 0 END) AS moved_out', [$locationId])
            ->where(fn ($q) => $q->where('to_location_id', $locationId)->orWhere('from_location_id', $locationId))
            ->groupBy('part_id')
            ->get()
            ->keyBy('part_id');

        if ($totals->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach (Part::whereIn('id', $totals->keys())->where('is_active', true)->get() as $part) {
            $total = $totals[$part->id];
            $qty = round((float) $total->moved_in - (float) $total->moved_out, 3);

            if (abs($qty) < 0.0005) {
                continue;
            }

            $rows[] = [
                'part_id' => $part->id,
                'sku' => $part->sku,
                'name' => $part->name,
                'qty' => $qty,
                'reorder_level' => (float) $part->reorder_level,
                'below_reorder' => $qty < (float) $part->reorder_level,
            ];
        }

        return $rows;
    }

    /**
     * Parts consumed on a visit, net of returns — the basis for visit cost.
     *
     * A visit under an active contract is billed the member price when one is
     * set. It was stored, seeded and editable in the panel while every invoice
     * silently charged full list price — the discount existed only on screen.
     */
    public function visitConsumption(Visit $visit): array
    {
        $visit->loadMissing('workOrder.contract');
        $isMember = $visit->workOrder?->contract?->isActive() ?? false;

        $rows = [];

        foreach ($visit->stockMoves()->with('part')->get() as $move) {
            $partId = $move->part_id;
            $sign = $move->move_type === StockMove::VISIT_ISSUE ? 1 : ($move->move_type === StockMove::VISIT_RETURN ? -1 : 0);

            if ($sign === 0) {
                continue;
            }

            $memberPrice = $move->part->member_price;
            $unitPrice = ($isMember && $memberPrice !== null)
                ? (float) $memberPrice
                : (float) $move->part->sale_price;

            $rows[$partId] ??= [
                'part_id' => $partId,
                'sku' => $move->part->sku,
                'name' => $move->part->name,
                'qty' => 0.0,
                'unit_cost' => (float) $move->unit_cost,
                'unit_price' => $unitPrice,
                'is_member_price' => $isMember && $memberPrice !== null,
            ];

            $rows[$partId]['qty'] += $sign * (float) $move->qty;
        }

        return array_values(array_filter($rows, fn ($r) => abs($r['qty']) > 0.0005));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }

    /**
     * Transaction-scoped lock on one part at one location.
     *
     * PostgreSQL gets an advisory lock, released automatically at commit or
     * rollback. SQLite serialises writers already, so there is nothing to add —
     * and MySQL would need its own named lock if it were ever supported.
     */
    private function lockPartLocation(int $partId, int $locationId): void
    {
        if ($locationId === 0) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [$partId, $locationId]);
        }
    }

    /** Returning more than was issued would manufacture stock out of nothing. */
    private function assertReturnWithinIssue(StockMove $original, float $qty): void
    {
        $alreadyReturned = (float) StockMove::where('reversal_of_id', $original->id)
            ->where('move_type', StockMove::VISIT_RETURN)
            ->sum('qty');

        $remaining = (float) $original->qty - $alreadyReturned;

        if (round($qty, 3) > round($remaining, 3) + 0.0005) {
            throw new RuntimeException(sprintf(
                'Cannot return %.3f: only %.3f of that issue remains.',
                $qty,
                max(0, $remaining),
            ));
        }
    }

    private function assertSufficientStock(int $partId, int $locationId, float $qty, string $moveType): void
    {
        if ($moveType === StockMove::ADJUSTMENT) {
            return; // stocktake may legitimately write down to reality
        }

        $available = $this->balance($partId, $locationId);

        if ($available + 0.0005 < $qty) {
            $location = StockLocation::find($locationId);
            throw new RuntimeException(sprintf(
                'Insufficient stock at [%s]: need %.3f, available %.3f.',
                $location?->name ?? $locationId,
                $qty,
                $available,
            ));
        }
    }
}

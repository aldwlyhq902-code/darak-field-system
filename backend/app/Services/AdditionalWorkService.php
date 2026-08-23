<?php

namespace App\Services;

use App\Models\AdditionalWorkApproval;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdditionalWorkService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<int, array{description:string,qty:float|int,unit_price:float|int,part_id?:int|null}> $items */
    public function create(Visit $visit, string $title, string $description, array $items, int $actorId, string $source, ?float $manualAmount = null): AdditionalWorkApproval
    {
        return DB::transaction(function () use ($visit, $title, $description, $items, $actorId, $source, $manualAmount): AdditionalWorkApproval {
            $visit->loadMissing('workOrder');
            $normalized = collect($items)->map(function (array $item): array {
                $qty = round((float) $item['qty'], 3);
                $unitPrice = round((float) $item['unit_price'], 2);

                return [
                    'part_id' => $item['part_id'] ?? null,
                    'description' => trim($item['description']),
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($qty * $unitPrice, 2),
                ];
            })->values()->all();
            $amount = $normalized === [] ? round((float) $manualAmount, 2) : round((float) collect($normalized)->sum('line_total'), 2);
            $vat = round($amount * .15, 2);
            $approval = AdditionalWorkApproval::create([
                'public_reference' => (string) Str::uuid(), 'visit_id' => $visit->id,
                'client_id' => $visit->workOrder->client_id, 'title' => $title,
                'description' => $description, 'items' => $normalized, 'amount' => $amount,
                'vat_amount' => $vat, 'total_amount' => $amount + $vat, 'status' => 'pending',
                'sent_at' => now(), 'created_by' => $actorId, 'requested_from' => $source,
            ]);
            $this->audit->record('additional_work.sent', $approval, null, [
                'visit_id' => $visit->id, 'amount' => $amount, 'total_amount' => $amount + $vat,
                'items' => $normalized, 'source' => $source,
            ], $actorId);

            return $approval;
        });
    }
}

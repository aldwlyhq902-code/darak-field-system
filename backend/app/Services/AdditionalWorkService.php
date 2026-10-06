<?php

namespace App\Services;

use App\Models\AdditionalWorkApproval;
use App\Models\Visit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdditionalWorkService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<int, array{description:string,qty:float|int|string,unit_price:float|int|string,part_id?:int|null}> $items */
    public function create(Visit $visit, string $title, string $description, array $items, int $actorId, string $source, int|float|string|null $manualAmount = null): AdditionalWorkApproval
    {
        return DB::transaction(function () use ($visit, $title, $description, $items, $actorId, $source, $manualAmount): AdditionalWorkApproval {
            $visit->loadMissing('workOrder');
            $normalized = collect($items)->map(function (array $item): array {
                $qty = round((float) $item['qty'], 3);
                $unitPrice = Money::of($item['unit_price']);

                return [
                    'part_id' => $item['part_id'] ?? null,
                    'description' => trim($item['description']),
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => Money::mul($qty, $unitPrice),
                ];
            })->values()->all();
            $amount = $normalized === [] ? Money::of($manualAmount) : Money::sum(array_column($normalized, 'line_total'));
            $vat = Money::vat($amount, '0.15');
            $approval = AdditionalWorkApproval::create([
                'public_reference' => (string) Str::uuid(), 'visit_id' => $visit->id,
                'client_id' => $visit->workOrder->client_id, 'title' => $title,
                'description' => $description, 'items' => $normalized, 'amount' => $amount,
                'vat_amount' => $vat, 'total_amount' => Money::add($amount, $vat), 'status' => 'pending',
                'sent_at' => now(), 'created_by' => $actorId, 'requested_from' => $source,
            ]);
            $this->audit->record('additional_work.sent', $approval, null, [
                'visit_id' => $visit->id, 'amount' => $amount, 'total_amount' => Money::add($amount, $vat),
                'items' => $normalized, 'source' => $source,
            ], $actorId);

            return $approval;
        });
    }
}

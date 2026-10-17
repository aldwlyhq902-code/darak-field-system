<?php

namespace App\Http\Resources\Api;

use App\Models\StockLocation;
use App\Models\VehicleStockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A van-to-van transfer as a technician's phone sees it (contract C5).
 *
 * These endpoints returned the raw model with its relations, so the phone got
 * the other technician's email, phone, hourly cost and permissions, and the
 * part's purchase, sale and member prices. The app reads the id, number, part
 * name and quantity; the rest is who is on either end, by name only.
 *
 * @mixin VehicleStockTransfer
 */
class VehicleTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var VehicleStockTransfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'transfer_no' => $transfer->transfer_no,
            'status' => $transfer->status,
            'qty' => (float) $transfer->qty,
            'part' => $transfer->part === null ? null : [
                'id' => $transfer->part->id,
                'sku' => $transfer->part->sku,
                'name' => $transfer->part->name,
                'unit' => $transfer->part->unit,
            ],
            'from' => self::end($transfer->fromLocation),
            'to' => self::end($transfer->toLocation),
            'released_at' => $transfer->released_at?->toIso8601String(),
            'accepted_at' => $transfer->accepted_at?->toIso8601String(),
        ];
    }

    /** @return array{name: string, technician: ?string}|null */
    private static function end(?StockLocation $location): ?array
    {
        return $location === null ? null : [
            'name' => $location->name,
            'technician' => $location->vehicle?->assignedUser?->name,
        ];
    }
}

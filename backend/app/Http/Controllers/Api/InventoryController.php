<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InvoiceService $invoices,
    ) {
    }

    public function balances(StockLocation $location): JsonResponse
    {
        return response()->json([
            'location' => ['id' => $location->id, 'name' => $location->name, 'type' => $location->type],
            'balances' => $this->inventory->locationBalances($location->id),
        ]);
    }

    public function receipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'to_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $move = $this->inventory->receipt(
            $data['idempotency_key'],
            $data['part_id'],
            (float) $data['qty'],
            $data['to_location_id'],
            ['note' => $data['note'] ?? null, 'user_id' => $request->user()->id],
        );

        return response()->json(['data' => $move], 201);
    }

    public function vehicleLoad(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'from_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
        ]);

        $move = $this->inventory->loadVehicle(
            $data['idempotency_key'],
            $data['part_id'],
            (float) $data['qty'],
            $data['from_location_id'],
            $data['to_location_id'],
            ['user_id' => $request->user()->id],
        );

        return response()->json(['data' => $move], 201);
    }

    /**
     * Return a part. Corrects stock and cost, and — when the visit was already
     * invoiced — requests a CREDIT NOTE. The issued invoice is never modified.
     */
    public function returnPart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'original_move_id' => ['required', 'integer', 'exists:stock_moves,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'to_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'request_credit_note' => ['nullable', 'boolean'],
        ]);

        $original = StockMove::findOrFail($data['original_move_id']);

        $move = $this->inventory->returnFromVisit(
            $data['idempotency_key'],
            $original,
            (float) $data['qty'],
            $data['to_location_id'],
            ['user_id' => $request->user()->id],
        );

        $creditNote = null;

        if ($request->boolean('request_credit_note', true)) {
            try {
                $creditNote = $this->invoices->creditNoteForReturn($move);
            } catch (\RuntimeException $e) {
                // No issued invoice yet is the normal case — the return simply
                // adjusts stock and nothing is owed back.
                $creditNote = ['skipped' => $e->getMessage()];
            }
        }

        return response()->json(['data' => $move, 'credit_note' => $creditNote], 201);
    }
}

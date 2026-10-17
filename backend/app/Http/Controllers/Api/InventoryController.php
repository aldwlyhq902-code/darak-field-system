<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\StocktakeSessionResource;
use App\Http\Resources\Api\VehicleTransferResource;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\StocktakeSession;
use App\Models\VehicleStockTransfer;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\ProcurementService;
use App\Services\StocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InvoiceService $invoices,
        private readonly ProcurementService $procurement,
        private readonly StocktakeService $stocktakes,
    ) {}

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
        // `exists` ignores the tenant scope; the scoped load is the authorization.
        StockLocation::query()->findOrFail($data['to_location_id']);

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
        StockLocation::query()->findOrFail($data['from_location_id']);
        StockLocation::query()->findOrFail($data['to_location_id']);

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
        StockLocation::query()->findOrFail($data['to_location_id']);

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

    private const TRANSFER_RELATIONS = ['part', 'fromLocation.vehicle.assignedUser', 'toLocation.vehicle.assignedUser'];

    public function pendingVehicleTransfers(Request $request): JsonResponse
    {
        $transfers = VehicleStockTransfer::with(self::TRANSFER_RELATIONS)
            ->whereIn('status', ['pending', 'pending_receive'])
            ->whereHas('toLocation.vehicle', fn ($query) => $query->where('assigned_user_id', $request->user()->id))
            ->latest()->get();

        return response()->json(['data' => VehicleTransferResource::collection($transfers)->resolve($request)]);
    }

    public function pendingVehicleTransferReleases(Request $request): JsonResponse
    {
        $transfers = VehicleStockTransfer::with(self::TRANSFER_RELATIONS)
            ->where('status', 'pending_release')
            ->whereHas('fromLocation.vehicle', fn ($query) => $query->where('assigned_user_id', $request->user()->id))
            ->latest()->get();

        return response()->json(['data' => VehicleTransferResource::collection($transfers)->resolve($request)]);
    }

    public function releaseVehicleTransfer(Request $request, VehicleStockTransfer $transfer): JsonResponse
    {
        $transfer->loadMissing('fromLocation.vehicle');
        abort_unless($transfer->fromLocation?->vehicle?->assigned_user_id === $request->user()->id, 403);
        $this->procurement->releaseTransfer($transfer, $request->user()->id);

        return response()->json(['data' => (new VehicleTransferResource($transfer->refresh()->load(self::TRANSFER_RELATIONS)))->resolve($request)]);
    }

    public function acceptVehicleTransfer(Request $request, VehicleStockTransfer $transfer): JsonResponse
    {
        $transfer->loadMissing('toLocation.vehicle');
        abort_unless($transfer->toLocation?->vehicle?->assigned_user_id === $request->user()->id, 403);
        $this->procurement->acceptTransfer($transfer, $request->user()->id);

        return response()->json(['data' => (new VehicleTransferResource($transfer->refresh()->load(self::TRANSFER_RELATIONS)))->resolve($request)]);
    }

    public function stocktakes(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        // Counted by the person it was assigned to; the van's driver only when
        // nobody was assigned (a second pair of eyes on one's own van).
        $sessions = StocktakeSession::with(['location', 'scannedLines.part'])
            ->where('status', StocktakeSession::STATUS_OPEN)
            ->where(fn ($query) => $query
                ->where('assigned_user_id', $userId)
                ->orWhere(fn ($unassigned) => $unassigned
                    ->whereNull('assigned_user_id')
                    ->whereHas('location.vehicle', fn ($vehicle) => $vehicle->where('assigned_user_id', $userId))))
            ->get();

        return response()->json(['data' => StocktakeSessionResource::collection($sessions)->resolve($request)]);
    }

    public function scanStocktake(Request $request, StocktakeSession $session): JsonResponse
    {
        $this->authorizeStocktake($request, $session);
        $data = $request->validate(['code' => ['required', 'string', 'max:96'], 'qty' => ['required', 'numeric', 'min:0']]);
        $session = $this->stocktakes->scan($session, $data['code'], (float) $data['qty']);

        return response()->json(['data' => (new StocktakeSessionResource($session->unsetRelation('scannedLines')))->resolve($request)]);
    }

    /**
     * The phone's "close" submits the count for review; it no longer posts the
     * variances. A back-office user approves them in the panel.
     */
    public function completeStocktake(Request $request, StocktakeSession $session): JsonResponse
    {
        $this->authorizeStocktake($request, $session);

        return response()->json(['data' => (new StocktakeSessionResource($this->stocktakes->submit($session, $request->user()->id)))->resolve($request)]);
    }

    private function authorizeStocktake(Request $request, StocktakeSession $session): void
    {
        $session->loadMissing('location.vehicle');
        $userId = $request->user()->id;
        abort_unless(
            $session->assigned_user_id === $userId
            || ($session->assigned_user_id === null && $session->location?->vehicle?->assigned_user_id === $userId),
            403,
        );
    }
}

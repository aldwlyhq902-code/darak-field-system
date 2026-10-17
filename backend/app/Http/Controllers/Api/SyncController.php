<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BootstrapVisitResource;
use App\Models\Device;
use App\Models\Part;
use App\Models\StockLocation;
use App\Models\Visit;
use App\Services\InventoryService;
use App\Services\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Everything the device needs to work for a day with no network: today's and
     * tomorrow's visits, their sites, assets, checklist templates and the vehicle's
     * current part balances.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = CarbonImmutable::now();

        // Today and tomorrow, PLUS anything still open regardless of date.
        //
        // A pure date window dropped yesterday's unfinished visit the moment the
        // clock passed midnight — a technician still on site at 00:20 watched the
        // job disappear from the device along with its assets and close button.
        $visits = Visit::with([
            'workOrder.contract', 'workOrder.asset', 'site.client', 'site.assets',
            'checklistInstances', 'mediaFiles', 'stockReservations.part', 'stockReservations.location',
            'additionalWorkApprovals',
        ])
            ->where('assigned_user_id', $user->id)
            ->where(fn ($q) => $q
                ->whereBetween('scheduled_start', [$now->startOfDay(), $now->addDay()->endOfDay()])
                ->orWhereNotIn('state', [Visit::STATE_COMPLETED]))
            ->orderBy('scheduled_start')
            ->get();

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'visits' => $visits->map(fn (Visit $v) => (new BootstrapVisitResource($v, $now))->resolve($request))->all(),
            'vehicle_stock' => $this->vehicleStock($user->id),
        ]);
    }

    /**
     * The parts in this technician's van, so a part can be issued to a visit
     * with no network. The docblock above promised this all along; without it
     * the phone had no way to record a part at all, and the close gate pushed
     * the technician to declare "no parts used" — the stock leak it exists for.
     *
     * Allowlisted like the visits (C5): no purchase cost or sale price leaves the
     * server. On-hand quantity is a hint; the server still refuses an issue the
     * van cannot cover (STOCK_MOVE_REFUSED).
     *
     * @return array{location_id:int, name:string, parts:list<array<string, mixed>>}|null
     */
    private function vehicleStock(int $userId): ?array
    {
        $location = StockLocation::query()
            ->where('type', StockLocation::TYPE_VEHICLE)
            ->where('is_active', true)
            ->whereHas('vehicle', fn ($q) => $q->where('assigned_user_id', $userId))
            ->orderBy('id')
            ->first();

        if ($location === null) {
            return null;
        }

        $balances = collect($this->inventory->locationBalances($location->id))->keyBy('part_id');
        $parts = Part::query()->whereIn('id', $balances->keys())->get()->keyBy('id');

        return [
            'location_id' => $location->id,
            'name' => $location->name,
            'parts' => $balances
                ->filter(fn (array $row) => $row['qty'] > 0 && $parts->has($row['part_id']))
                ->map(fn (array $row) => [
                    'part_id' => $row['part_id'],
                    'sku' => $row['sku'],
                    'qr_code' => $parts[$row['part_id']]->qr_code,
                    'name' => $row['name'],
                    'unit' => $parts[$row['part_id']]->unit,
                    'qty' => $row['qty'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Batch event ingest. Replay-safe: the same client_event_id may be sent any
     * number of times and will take effect exactly once.
     */
    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'last_trusted_server_time' => ['nullable', 'date'],
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.client_event_id' => ['required', 'uuid'],
            'events.*.visit_id' => ['required', 'integer'],
            'events.*.event_type' => ['required', 'string', 'max:48'],
            'events.*.payload' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    $bytes = strlen(json_encode($value, JSON_THROW_ON_ERROR));
                } catch (\JsonException) {
                    $fail("The {$attribute} field must be valid JSON.");

                    return;
                }

                if ($bytes > SyncService::MAX_PAYLOAD_BYTES) {
                    $fail("The {$attribute} field is too large.");
                }
            }],
            'events.*.device_timestamp' => ['nullable', 'date'],
            'events.*.monotonic_offset_ms' => ['nullable', 'integer'],
            'events.*.last_trusted_server_time' => ['nullable', 'date'],
            'events.*.sequence' => ['nullable', 'integer'],
            'events.*.lat' => ['nullable', 'numeric'],
            'events.*.lng' => ['nullable', 'numeric'],
            // C3 location quality: lenient on purpose. A batch must not fail
            // over a GPS detail; the service ignores values it cannot use.
            'events.*.accuracy_m' => ['nullable', 'numeric'],
            'events.*.is_mocked' => ['nullable', 'boolean'],
            // C6: compared per event in SyncService (ACTOR_MISMATCH).
            'events.*.actor_user_id' => ['nullable', 'integer'],
            'events.*.source' => ['nullable', 'string', 'max:24'],
            'events.*.integrity_hash' => ['nullable', 'string', 'max:64'],
        ]);

        $device = Device::where('device_uuid', $data['device_uuid'])->firstOrFail();

        if ($device->user_id !== $request->user()->id) {
            return response()->json([
                'code' => 'DEVICE_MISMATCH',
                'message' => 'This device is registered to another user.',
            ], 403);
        }

        $result = $this->sync->ingest($device, $data['events'], $data['last_trusted_server_time'] ?? null);

        return response()->json($result);
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenanceOrder;
use App\Models\VehicleOutage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FleetManagementService
{
    public function __construct(private readonly AutomaticReassignmentService $reassignment) {}

    /** @param array<string, mixed> $data */
    public function transition(VehicleMaintenanceOrder $order, string $status, array $data, User $actor): int
    {
        $allowed = [
            'open' => ['approved', 'cancelled'],
            'approved' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
        ];
        if (! in_array($status, $allowed[$order->status] ?? [], true)) {
            throw new RuntimeException("الانتقال من {$order->status} إلى {$status} غير مسموح.");
        }

        $needsReassignment = false;
        DB::transaction(function () use ($order, $status, $data, $actor, &$needsReassignment): void {
            if ($status === 'approved') {
                $order->forceFill(['status' => $status, 'approved_by' => $actor->id, 'notes' => $data['notes'] ?? $order->notes])->save();

                return;
            }

            if ($status === 'in_progress') {
                $order->forceFill([
                    'status' => $status, 'vendor_name' => $data['vendor_name'] ?? $order->vendor_name,
                    'quote_reference' => $data['quote_reference'] ?? $order->quote_reference,
                    'estimated_cost' => $data['estimated_cost'] ?? $order->estimated_cost,
                    'notes' => $data['notes'] ?? $order->notes,
                ])->save();
                if ($order->causes_outage) {
                    $outage = VehicleOutage::create([
                        'vehicle_id' => $order->vehicle_id, 'starts_at' => now(),
                        'reason' => "أمر صيانة {$order->order_no}", 'status' => 'open', 'reported_by' => $actor->id,
                    ]);
                    $order->forceFill(['vehicle_outage_id' => $outage->id])->save();
                    $order->vehicle->forceFill(['operational_status' => 'out_of_service'])->save();
                    $needsReassignment = true;
                }

                return;
            }

            if ($status === 'completed') {
                $actualCost = (float) ($data['actual_cost'] ?? 0);
                $completedOn = $data['completed_on'] ?? now()->toDateString();
                $order->forceFill([
                    'status' => $status, 'actual_cost' => $actualCost, 'completed_on' => $completedOn,
                    'odometer_km' => $data['odometer_km'] ?? $order->odometer_km,
                    'next_service_on' => $data['next_service_on'] ?? null,
                    'next_service_odometer_km' => $data['next_service_odometer_km'] ?? null,
                    'completed_by' => $actor->id, 'notes' => $data['notes'] ?? $order->notes,
                ])->save();
                VehicleExpense::firstOrCreate(
                    ['vehicle_maintenance_order_id' => $order->id],
                    [
                        'vehicle_id' => $order->vehicle_id, 'category' => 'maintenance',
                        'amount' => $actualCost, 'incurred_on' => $completedOn,
                        'odometer_km' => $order->odometer_km, 'reference' => $order->order_no,
                        'note' => $order->description, 'recorded_by' => $actor->id,
                    ],
                );
                $order->vehicle->forceFill([
                    'operational_status' => 'available', 'last_service_on' => $completedOn,
                    'current_odometer_km' => max((float) $order->vehicle->current_odometer_km, (float) ($order->odometer_km ?? 0)),
                    'next_service_on' => $order->next_service_on,
                    'next_service_odometer_km' => $order->next_service_odometer_km,
                ])->save();
                $order->outage?->forceFill(['status' => 'closed', 'ends_at' => now()])->save();

                return;
            }

            $order->forceFill(['status' => 'cancelled', 'notes' => $data['notes'] ?? $order->notes])->save();
            if ($order->outage?->status === 'open') {
                $order->outage->forceFill(['status' => 'closed', 'ends_at' => now()])->save();
                $order->vehicle->forceFill(['operational_status' => 'available'])->save();
            }
        });

        return $needsReassignment ? $this->reassignment->run() : 0;
    }

    /** @param array<string, bool> $checklist */
    public function recordInspection(Vehicle $vehicle, User $inspector, float $odometer, array $checklist, ?string $defects, array $file = []): array
    {
        $roadworthy = collect(['tires', 'brakes', 'lights', 'fluids'])->every(fn (string $item) => $checklist[$item] ?? false);
        $inspection = DB::transaction(function () use ($vehicle, $inspector, $odometer, $checklist, $defects, $file, $roadworthy): VehicleInspection {
            $inspection = VehicleInspection::create([
                'inspection_no' => 'VINSP-'.now()->format('ymd').'-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
                'vehicle_id' => $vehicle->id, 'operating_branch_id' => $vehicle->operating_branch_id,
                'inspected_by' => $inspector->id, 'inspected_at' => now(), 'odometer_km' => $odometer,
                'checklist' => $checklist, 'is_roadworthy' => $roadworthy, 'defects' => $defects,
            ] + $file);
            $vehicle->forceFill(['current_odometer_km' => max((float) $vehicle->current_odometer_km, $odometer)])->save();
            if (! $roadworthy) {
                VehicleOutage::create([
                    'vehicle_id' => $vehicle->id, 'starts_at' => now(),
                    'reason' => "فشل فحص السلامة {$inspection->inspection_no}", 'status' => 'open', 'reported_by' => $inspector->id,
                ]);
                $vehicle->forceFill(['operational_status' => 'out_of_service'])->save();
            }

            return $inspection;
        });

        return ['inspection' => $inspection, 'reassigned' => $roadworthy ? 0 : $this->reassignment->run()];
    }
}

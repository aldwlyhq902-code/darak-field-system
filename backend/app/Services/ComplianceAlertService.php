<?php

namespace App\Services;

use App\Models\EmployeeDocument;
use App\Models\NotificationMessage;
use App\Models\Vehicle;
use App\Models\VehicleDocument;

class ComplianceAlertService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function scan(): int
    {
        $count = 0;
        EmployeeDocument::withoutGlobalScopes()->with('user')->where('status', 'active')->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays(90))->get()->each(function (EmployeeDocument $document) use (&$count): void {
                $days = now()->startOfDay()->diffInDays($document->expires_on, false);
                $threshold = $this->threshold($days);
                $label = ['iqama' => 'الإقامة', 'passport' => 'الجواز', 'work_permit' => 'رخصة العمل', 'medical_insurance' => 'التأمين الطبي', 'contract' => 'العقد'][$document->type] ?? 'وثيقة الموظف';
                $timing = $days < 0 ? 'منتهية منذ '.abs($days).' يوم' : ($days === 0 ? 'تنتهي اليوم' : "تنتهي بعد {$days} يوم");
                $key = "employee-document:{$document->id}:{$document->expires_on->format('Ymd')}:{$threshold}";
                $exists = NotificationMessage::where('idempotency_key', $key)->exists();
                $message = $this->notifications->complianceAlert(
                    NotificationMessage::TYPE_EMPLOYEE_DOCUMENT_EXPIRING,
                    $key,
                    "{$label} للموظف {$document->user->name} {$timing}.",
                    ['employee_document_id' => $document->id, 'operating_branch_id' => $document->operating_branch_id, 'days' => $days],
                );
                $count += ! $exists && $message ? 1 : 0;
            });

        VehicleDocument::withoutGlobalScopes()->with('vehicle')->where('status', 'active')->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays(90))->get()->each(function (VehicleDocument $document) use (&$count): void {
                $days = now()->startOfDay()->diffInDays($document->expires_on, false);
                $threshold = $this->threshold($days);
                $label = ['registration' => 'الاستمارة', 'insurance' => 'التأمين', 'inspection' => 'الفحص الدوري', 'operation_card' => 'بطاقة التشغيل', 'authorization' => 'التفويض'][$document->type] ?? 'وثيقة السيارة';
                $timing = $days < 0 ? 'منتهية منذ '.abs($days).' يوم' : ($days === 0 ? 'تنتهي اليوم' : "تنتهي بعد {$days} يوم");
                $key = "vehicle-document:{$document->id}:{$document->expires_on->format('Ymd')}:{$threshold}";
                $exists = NotificationMessage::where('idempotency_key', $key)->exists();
                $message = $this->notifications->complianceAlert(
                    NotificationMessage::TYPE_VEHICLE_DOCUMENT_EXPIRING,
                    $key,
                    "{$label} للسيارة {$document->vehicle->plate} {$timing}.",
                    ['vehicle_document_id' => $document->id, 'operating_branch_id' => $document->operating_branch_id, 'days' => $days],
                );
                $count += ! $exists && $message ? 1 : 0;
            });

        Vehicle::withoutGlobalScopes()->where('is_active', true)->where(function ($query): void {
            $query->whereNotNull('next_service_on')->orWhereNotNull('next_service_odometer_km');
        })->get()->each(function (Vehicle $vehicle) use (&$count): void {
            $dateDue = $vehicle->next_service_on ? now()->startOfDay()->diffInDays($vehicle->next_service_on, false) : null;
            $kmRemaining = $vehicle->next_service_odometer_km !== null ? (float) $vehicle->next_service_odometer_km - (float) $vehicle->current_odometer_km : null;
            if (($dateDue === null || $dateDue > 30) && ($kmRemaining === null || $kmRemaining > 500)) {
                return;
            }
            $keyStage = $dateDue !== null && $dateDue <= 30 ? 'd'.$this->maintenanceThreshold($dateDue) : 'km'.($kmRemaining <= 0 ? 'due' : '500');
            $body = "صيانة السيارة {$vehicle->plate} مستحقة".($dateDue !== null ? " خلال {$dateDue} يوم" : '').($kmRemaining !== null ? "، المتبقي {$kmRemaining} كم" : '').'.';
            $key = "vehicle-maintenance:{$vehicle->id}:{$keyStage}:".($vehicle->next_service_on?->format('Ymd') ?? 'none').':'.($vehicle->next_service_odometer_km ?? 'none');
            $exists = NotificationMessage::where('idempotency_key', $key)->exists();
            $message = $this->notifications->complianceAlert(
                NotificationMessage::TYPE_VEHICLE_MAINTENANCE_DUE,
                $key,
                $body,
                ['vehicle_id' => $vehicle->id, 'operating_branch_id' => $vehicle->operating_branch_id, 'days' => $dateDue, 'km_remaining' => $kmRemaining],
            );
            $count += ! $exists && $message ? 1 : 0;
        });

        return $count;
    }

    private function threshold(int $days): string
    {
        return match (true) {
            $days < 0 => 'expired', $days <= 0 => '0', $days <= 7 => '7',
            $days <= 30 => '30', $days <= 60 => '60', default => '90',
        };
    }

    private function maintenanceThreshold(int $days): string
    {
        return match (true) {
            $days <= 0 => 'due', $days <= 7 => '7', default => '30'
        };
    }
}

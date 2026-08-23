<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenanceOrder extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['order_no', 'vehicle_id', 'operating_branch_id', 'type', 'priority', 'status', 'description', 'vendor_name', 'quote_reference', 'estimated_cost', 'actual_cost', 'opened_on', 'scheduled_for', 'completed_on', 'odometer_km', 'next_service_on', 'next_service_odometer_km', 'causes_outage', 'vehicle_outage_id', 'created_by', 'approved_by', 'completed_by', 'notes'];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2',
            'opened_on' => 'date', 'scheduled_for' => 'date', 'completed_on' => 'date',
            'odometer_km' => 'decimal:1', 'next_service_on' => 'date',
            'next_service_odometer_km' => 'decimal:1', 'causes_outage' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(VehicleOutage::class, 'vehicle_outage_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleExpense extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['vehicle_id', 'category', 'amount', 'incurred_on', 'odometer_km', 'reference', 'note', 'recorded_by', 'vehicle_maintenance_order_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'incurred_on' => 'date', 'odometer_km' => 'decimal:1'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}

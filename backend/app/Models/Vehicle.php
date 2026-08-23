<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    protected $fillable = ['plate', 'internal_code', 'make', 'model', 'year', 'vin', 'assigned_user_id', 'is_active', 'operating_branch_id', 'operational_status', 'current_odometer_km', 'last_service_on', 'next_service_on', 'next_service_odometer_km'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'current_odometer_km' => 'decimal:1',
            'last_service_on' => 'date', 'next_service_on' => 'date',
            'next_service_odometer_km' => 'decimal:1',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function stockLocation(): HasOne
    {
        return $this->hasOne(StockLocation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function maintenanceOrders(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceOrder::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
    }
}

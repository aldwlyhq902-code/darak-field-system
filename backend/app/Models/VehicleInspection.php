<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleInspection extends Model
{
    use ScopedToOperatingBranch;

    protected $hidden = ['photo_path'];

    protected $fillable = ['inspection_no', 'vehicle_id', 'operating_branch_id', 'inspected_by', 'inspected_at', 'odometer_km', 'checklist', 'is_roadworthy', 'defects', 'photo_path', 'photo_name', 'photo_mime_type', 'photo_size'];

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime', 'odometer_km' => 'decimal:1', 'checklist' => 'array', 'is_roadworthy' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}

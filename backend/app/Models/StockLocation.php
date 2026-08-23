<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLocation extends Model
{
    use HasFactory, ScopedToOperatingBranch;

    public const TYPE_WAREHOUSE = 'warehouse';

    public const TYPE_VEHICLE = 'vehicle';

    protected $fillable = ['type', 'name', 'vehicle_id', 'is_active', 'operating_branch_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}

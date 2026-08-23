<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Custody extends Model
{
    use HasFactory;
    use ScopedToOperatingBranch;

    protected $fillable = ['custody_no', 'user_id', 'vehicle_id', 'stock_location_id', 'item_type', 'item_name', 'serial_number', 'condition_out', 'condition_in', 'issued_at', 'accepted_at', 'returned_at', 'status', 'issued_by', 'returned_to', 'accepted_signature_hash', 'accepted_name', 'loss_amount', 'loss_note'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'accepted_at' => 'datetime', 'returned_at' => 'datetime', 'loss_amount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}

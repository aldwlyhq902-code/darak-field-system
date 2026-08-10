<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'client_event_id', 'event_type', 'payload',
        'device_id', 'actor_user_id', 'device_timestamp', 'monotonic_offset_ms',
        'last_trusted_server_time', 'server_received_at',
        'clock_divergence_seconds', 'clock_suspect',
        'lat', 'lng', 'source', 'integrity_hash', 'sync_status', 'sequence',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'device_timestamp' => 'datetime',
            'last_trusted_server_time' => 'datetime',
            'server_received_at' => 'datetime',
            'clock_suspect' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

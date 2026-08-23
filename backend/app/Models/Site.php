<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory, ScopedToOperatingBranch, SoftDeletes;

    protected $fillable = [
        'client_id', 'name', 'code', 'address', 'lat', 'lng',
        'geofence_radius_m', 'dwell_threshold_s', 'access_notes',
        'no_visit_windows', 'qr_code', 'emergency_public_token', 'emergency_qr_secret', 'is_active',
    ];

    protected $hidden = ['emergency_public_token', 'emergency_qr_secret'];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'no_visit_windows' => 'array',
            'emergency_qr_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            if ($site->emergency_qr_secret === null) {
                $site->rotateEmergencyQr();
            }
        });
    }

    public function rotateEmergencyQr(): string
    {
        $secret = Str::random(48);
        $this->emergency_qr_secret = $secret;
        $this->emergency_public_token = hash('sha256', $secret);

        return $secret;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function emergencyReports(): HasMany
    {
        return $this->hasMany(EmergencyReport::class);
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ClientServiceRequest::class);
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_site')->withTimestamps();
    }
}

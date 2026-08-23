<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class ClientPortalUser extends Authenticatable
{
    public const DEFAULT_PERMISSIONS = [
        'quotes.approve', 'contracts.sign', 'reports.dispute',
        'assets.history', 'service.request', 'additional-work.approve',
    ];

    protected $fillable = [
        'client_id', 'name', 'email', 'phone', 'password', 'is_active', 'permissions',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ClientServiceRequest::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(VisitFeedback::class);
    }

    public function allowedSites()
    {
        return $this->belongsToMany(Site::class, 'client_portal_user_site');
    }

    public function canPortal(string $permission): bool
    {
        $permissions = $this->permissions ?? self::DEFAULT_PERMISSIONS;

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function canAccessSite(int $siteId): bool
    {
        return ! $this->allowedSites()->exists() || $this->allowedSites()->whereKey($siteId)->exists();
    }
}

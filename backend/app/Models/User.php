<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'phone', 'trade',
    'specialties', 'shift_start', 'shift_end', 'is_active',
    'operating_branch_id', 'operating_company_id', 'is_platform_admin',
    'hourly_cost', 'is_emergency_backup', 'permissions',
])]
#[Hidden([
    'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** MVP roles (PRD v1.2 §2). Sales rep and warehouse keeper are backlog. */
    public const ROLE_OWNER = 'owner_supervisor';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_TECHNICIAN, self::ROLE_ADMIN];

    private const DEFAULT_PANEL_PERMISSIONS = [
        self::ROLE_OWNER => ['*'],
        self::ROLE_ADMIN => [
            'operations', 'clients', 'commercial', 'finance', 'inventory',
            'intelligence', 'team', 'hr', 'fleet', 'performance', 'sales', 'admin',
        ],
        self::ROLE_TECHNICIAN => [],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'specialties' => 'array',
            'is_active' => 'boolean',
            'hourly_cost' => 'decimal:2',
            'is_emergency_backup' => 'boolean',
            'is_platform_admin' => 'boolean',
            'permissions' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'auth_version' => 'integer',
        ];
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'assigned_user_id');
    }

    public function operatingBranch(): BelongsTo
    {
        return $this->belongsTo(OperatingBranch::class, 'operating_branch_id');
    }

    public function operatingCompany(): BelongsTo
    {
        return $this->belongsTo(OperatingCompany::class, 'operating_company_id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function employeeDocuments(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function employeeLeaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class);
    }

    public function salesTargets(): HasMany
    {
        return $this->hasMany(SalesTarget::class);
    }

    public function webPushSubscriptions(): HasMany
    {
        return $this->hasMany(WebPushSubscription::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Manual dispatch still refuses assignments outside the technician's shift.
     * Note this is a scheduling guard only — it deliberately encodes NO legal
     * conclusion about overtime. Working-hours policy is supplied by a qualified
     * HR specialist (PRD v1.2 §3.4), not hard-coded here.
     */
    public function isWithinShift(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        if ($this->shift_start === null || $this->shift_end === null) {
            return true;
        }

        $shiftStart = substr((string) $this->shift_start, 0, 5);
        $shiftEnd = substr((string) $this->shift_end, 0, 5);

        return $start->format('H:i') >= $shiftStart && $end->format('H:i') <= $shiftEnd;
    }

    public function hasSpecialty(?string $specialty): bool
    {
        if ($specialty === null) {
            return true;
        }

        return in_array($specialty, $this->specialties ?? [], true);
    }

    public function canPanel(string $permission): bool
    {
        $permissions = $this->permissions;
        if ($permissions === null) {
            $permissions = self::DEFAULT_PANEL_PERMISSIONS[$this->role] ?? [];
        }

        return $this->isPlatformAdmin() || $this->isOwner() || in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}

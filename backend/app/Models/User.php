<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'phone', 'trade',
    'specialties', 'shift_start', 'shift_end', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** MVP roles (PRD v1.2 §2). Sales rep and warehouse keeper are backlog. */
    public const ROLE_OWNER = 'owner_supervisor';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_TECHNICIAN, self::ROLE_ADMIN];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'specialties' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'assigned_user_id');
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
}

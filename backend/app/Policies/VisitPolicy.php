<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;

/**
 * Authorisation lives at the API, not in hidden buttons. Acceptance criterion:
 * technician A requesting technician B's visit by direct URL or API gets 403.
 */
class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Visit $visit): bool
    {
        if ($user->isOwner() || $user->isAdmin()) {
            return true;
        }

        return $user->isTechnician() && $visit->assigned_user_id === $user->id;
    }

    public function transition(User $user, Visit $visit): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->isTechnician() && $visit->assigned_user_id === $user->id;
    }

    public function assign(User $user): bool
    {
        return $user->isOwner();
    }

    /** Only a supervisor may reclassify a system-flagged rework. */
    public function overrideRework(User $user): bool
    {
        return $user->isOwner();
    }
}

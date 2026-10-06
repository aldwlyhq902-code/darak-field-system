<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;

/**
 * Authorisation lives at the API, not in hidden buttons. Acceptance criterion:
 * technician A requesting technician B's visit by direct URL or API gets 403.
 *
 * Back-office access mirrors the panel: an admin reaches visits only with the
 * `operations` permission, so an admin limited to (say) sales can neither list
 * visits, pull report PDFs nor record diagnoses through the API.
 */
class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isTechnician() || $this->operations($user));
    }

    public function view(User $user, Visit $visit): bool
    {
        if ($this->operations($user)) {
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

    public function update(User $user, Visit $visit): bool
    {
        if ($this->operations($user)) {
            return true;
        }

        return $user->isTechnician() && $visit->assigned_user_id === $user->id;
    }

    /** Dispatch is an operations task, identical on the panel and the API. */
    public function assign(User $user): bool
    {
        return $this->operations($user);
    }

    /** Only a supervisor may reclassify a system-flagged rework: it changes billing. */
    public function overrideRework(User $user): bool
    {
        return $user->isOwner();
    }

    private function operations(User $user): bool
    {
        return $user->isOwner() || ($user->isAdmin() && $user->canPanel('operations'));
    }
}

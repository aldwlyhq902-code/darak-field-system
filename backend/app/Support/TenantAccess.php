<?php

namespace App\Support;

use App\Models\ClientPortalUser;
use App\Models\Device;
use App\Models\OperatingCompany;
use App\Models\ReportDispute;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Central authorization boundary for models that cannot use the branch scope directly. */
class TenantAccess
{
    public function assertCompany(User $actor, OperatingCompany|int|null $company, int $status = 404): void
    {
        $companyId = $company instanceof OperatingCompany ? $company->id : $company;
        abort_unless($this->allowsCompany($actor, $companyId), $status);
    }

    public function assertUser(User $actor, User $target, int $status = 404): void
    {
        $this->assertCompany($actor, $target->operating_company_id, $status);
    }

    public function assertDevice(User $actor, Device $device, int $status = 404): void
    {
        $companyId = User::query()->whereKey($device->user_id)->value('operating_company_id');
        $this->assertCompany($actor, $companyId, $status);
    }

    public function assertPortalUser(User $actor, ClientPortalUser $portalUser, int $status = 404): void
    {
        $companyId = DB::table('clients')->where('id', $portalUser->client_id)->value('operating_company_id');
        $this->assertCompany($actor, $companyId === null ? null : (int) $companyId, $status);
    }

    public function assertFeedback(User $actor, VisitFeedback $feedback, int $status = 404): void
    {
        $companyId = DB::table('visit_feedback')
            ->join('visits', 'visits.id', '=', 'visit_feedback.visit_id')
            ->join('sites', 'sites.id', '=', 'visits.site_id')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->where('visit_feedback.id', $feedback->id)
            ->value('clients.operating_company_id');
        $this->assertCompany($actor, $companyId === null ? null : (int) $companyId, $status);
    }

    public function assertDispute(User $actor, ReportDispute $dispute, int $status = 404): void
    {
        $companyId = DB::table('report_disputes')
            ->join('visits', 'visits.id', '=', 'report_disputes.visit_id')
            ->join('sites', 'sites.id', '=', 'visits.site_id')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->where('report_disputes.id', $dispute->id)
            ->value('clients.operating_company_id');
        $this->assertCompany($actor, $companyId === null ? null : (int) $companyId, $status);
    }

    /** The company owning a stock location (through its branch), read straight from the tables. */
    public function locationCompanyId(int $locationId): ?int
    {
        $companyId = DB::table('stock_locations')
            ->join('operating_branches', 'operating_branches.id', '=', 'stock_locations.operating_branch_id')
            ->where('stock_locations.id', $locationId)->value('operating_branches.operating_company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    public function allowsCompany(User $actor, ?int $companyId): bool
    {
        return $actor->isPlatformAdmin()
            || ($companyId !== null
                && $actor->operating_company_id !== null
                && (int) $actor->operating_company_id === $companyId);
    }

    /**
     * Users who may work for a client: same company and, when the client is
     * branch-bound, same branch. Read straight from the tables so a scheduled
     * command with no signed-in user applies exactly the same boundary.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function scopeUsersToClient(Builder $users, int $clientId): Builder
    {
        return $users->whereExists(fn ($query) => $this->matchingClient($query->selectRaw('1')
            ->from('clients as fit_clients')
            ->where('fit_clients.id', $clientId)));
    }

    /**
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function scopeUsersToVisit(Builder $users, Visit $visit): Builder
    {
        return $users->whereExists(fn ($query) => $this->matchingClient($query->selectRaw('1')
            ->from('sites as fit_sites')
            ->join('clients as fit_clients', 'fit_clients.id', '=', 'fit_sites.client_id')
            ->where('fit_sites.id', $visit->site_id)));
    }

    public function userFitsClient(User $user, int $clientId): bool
    {
        return $this->scopeUsersToClient(User::query()->whereKey($user->id), $clientId)->exists();
    }

    public function userFitsVisit(User $user, Visit $visit): bool
    {
        return $this->scopeUsersToVisit(User::query()->whereKey($user->id), $visit)->exists();
    }

    /**
     * Restrict a users query to the actor's company and, for a branch-bound
     * actor, branch. The User model deliberately carries no global scope.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function scopeUsersToActor(Builder $users, User $actor): Builder
    {
        if ($actor->isPlatformAdmin()) {
            return $users;
        }
        // Missing tenancy is never an implicit company-wide grant.
        if ($actor->operating_company_id === null) {
            return $users->whereRaw('1 = 0');
        }

        return $users->where('users.operating_company_id', $actor->operating_company_id)
            ->when($actor->operating_branch_id, fn ($scoped, $branchId) => $scoped->where('users.operating_branch_id', $branchId));
    }

    private function matchingClient($query)
    {
        return $query->whereNotNull('fit_clients.operating_company_id')
            ->whereColumn('fit_clients.operating_company_id', 'users.operating_company_id')
            ->where(fn ($branch) => $branch->whereNull('fit_clients.operating_branch_id')
                ->orWhereColumn('fit_clients.operating_branch_id', 'users.operating_branch_id'));
    }
}

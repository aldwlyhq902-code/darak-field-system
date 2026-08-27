<?php

namespace App\Support;

use App\Models\ClientPortalUser;
use App\Models\Device;
use App\Models\OperatingCompany;
use App\Models\ReportDispute;
use App\Models\User;
use App\Models\VisitFeedback;
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

    public function allowsCompany(User $actor, ?int $companyId): bool
    {
        return $actor->isPlatformAdmin()
            || ($companyId !== null
                && $actor->operating_company_id !== null
                && (int) $actor->operating_company_id === $companyId);
    }
}

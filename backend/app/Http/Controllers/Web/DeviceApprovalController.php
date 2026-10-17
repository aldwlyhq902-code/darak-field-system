<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AuditLogger;
use App\Services\RevokedTokens;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Approve or reject a technician's additional handset. Only the owner or a user
 * holding the `team` permission decides; the technician's next login after an
 * approval succeeds normally.
 */
class DeviceApprovalController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantAccess $tenantAccess,
        private readonly RevokedTokens $revokedTokens,
    ) {}

    public function approve(Request $request, Device $device): RedirectResponse
    {
        $this->authorizeDecision($request, $device);

        if ($device->isRevoked()) {
            return back()->with('err', 'لا يمكن اعتماد جهاز مُبطل.');
        }

        $device->forceFill([
            'approval_status' => Device::APPROVAL_APPROVED,
            'approved_at' => CarbonImmutable::now(),
            'approved_by' => $request->user()->id,
        ])->save();

        $this->audit->record('device.approved', $device, null, ['user_id' => $device->user_id]);

        return back()->with('ok', 'اعتُمد الجهاز. يمكن للفني تسجيل الدخول منه الآن.');
    }

    public function reject(Request $request, Device $device): RedirectResponse
    {
        $this->authorizeDecision($request, $device);

        // A rejected handset is revoked outright: the app treats DEVICE_REVOKED
        // as "wipe local data", which is exactly right for a phone nobody owns.
        $device->forceFill([
            'approval_status' => Device::APPROVAL_REJECTED,
            'revoked_at' => $device->revoked_at ?? CarbonImmutable::now(),
            'revoked_reason' => 'رُفض اعتماد الجهاز',
        ])->save();
        if ($device->user !== null) {
            $this->revokedTokens->revoke(
                $device->user->tokens()->where('name', 'device:'.$device->device_uuid),
                RevokedTokens::DEVICE_REVOKED,
            );
        }

        $this->audit->record('device.rejected', $device, null, ['user_id' => $device->user_id]);

        return back()->with('ok', 'رُفض الجهاز ولن يتمكن من الدخول.');
    }

    private function authorizeDecision(Request $request, Device $device): void
    {
        $actor = $request->user();
        abort_unless($actor->isOwner() || $actor->canPanel('team'), 403);
        $this->tenantAccess->assertDevice($actor, $device);
    }
}

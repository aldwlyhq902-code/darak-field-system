<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a login from a handset may receive a token.
 *
 * A technician's FIRST active device registers itself — onboarding stays one
 * login. Any further device is recorded as pending and gets no token until the
 * owner (or a team manager) approves it in the panel. Before this, a stolen
 * password minted a 30-day token on any phone without anyone being told.
 */
class DeviceRegistry
{
    public const STATUS_OK = 'ok';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_BOUND_TO_ANOTHER_USER = 'bound_to_another_user';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated login payload
     * @return array{status: string, device: Device}
     */
    public function register(User $user, array $data): array
    {
        $outcome = DB::transaction(function () use ($user, $data): array {
            // Serialise per account: two new phones logging in at the same
            // moment must not both be treated as "the first device".
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $device = Device::query()->where('device_uuid', $data['device_uuid'])->lockForUpdate()->first()
                ?? new Device(['device_uuid' => $data['device_uuid']]);

            if ($device->exists && $device->isRevoked()) {
                return ['status' => self::STATUS_REVOKED, 'device' => $device, 'created' => false];
            }

            if ($device->exists && $device->user_id !== $user->id) {
                return ['status' => self::STATUS_BOUND_TO_ANOTHER_USER, 'device' => $device, 'created' => false];
            }

            $created = ! $device->exists;
            $device->fill([
                'user_id' => $user->id,
                'platform' => $data['platform'] ?? 'android',
                'label' => $data['label'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => CarbonImmutable::now(),
            ]);

            if (isset($data['device_integrity']) && is_array($data['device_integrity'])) {
                $device->integrity = [
                    'rooted' => isset($data['device_integrity']['rooted']) ? (bool) $data['device_integrity']['rooted'] : null,
                    'emulator' => isset($data['device_integrity']['emulator']) ? (bool) $data['device_integrity']['emulator'] : null,
                    'reported_at' => CarbonImmutable::now()->toIso8601String(),
                ];
            }

            if ($created) {
                $needsApproval = $user->isTechnician() && $this->hasActiveApprovedDevice($user);
                $device->forceFill([
                    'approval_status' => $needsApproval ? Device::APPROVAL_PENDING : Device::APPROVAL_APPROVED,
                    'approved_at' => $needsApproval ? null : CarbonImmutable::now(),
                ]);
            }

            $device->save();

            return [
                'status' => $device->isApproved() ? self::STATUS_OK : self::STATUS_PENDING,
                'device' => $device,
                'created' => $created,
            ];
        });

        if ($outcome['created'] && $outcome['status'] === self::STATUS_PENDING) {
            $this->requestApproval($user, $outcome['device']);
        }

        return ['status' => $outcome['status'], 'device' => $outcome['device']];
    }

    private function hasActiveApprovedDevice(User $user): bool
    {
        return Device::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('approval_status', Device::APPROVAL_APPROVED)
            ->exists();
    }

    private function requestApproval(User $user, Device $device): void
    {
        $label = $device->label ?: $device->platform;

        $this->notifications->securityAlert(
            'device.pending_approval:'.$device->id,
            "جهاز جديد بانتظار الاعتماد: الفني {$user->name} سجّل الدخول من جهاز آخر ({$label}). "
                .'اعتمده أو ارفضه من صفحة الفريق — إن لم يكن الفني نفسه فارفضه وغيّر كلمة مروره.',
            $user->id,
            [
                'reason' => 'device_pending_approval',
                'device_id' => $device->id,
                'operating_branch_id' => $user->operating_branch_id,
            ],
        );

        $this->audit->record('device.pending_approval', $device, null, [
            'user_id' => $user->id,
            'integrity' => $device->integrity,
        ], $user->id);
    }
}

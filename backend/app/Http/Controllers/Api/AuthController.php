<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Tokens are bound to a DEVICE, not just a user. A lost technician phone is an
 * ordinary event, not a rare one: it carries client data, site photos and
 * signatures. Revoking one handset must not lock the person out of a replacement.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if (! $user->is_active) {
            return response()->json(['code' => 'USER_INACTIVE', 'message' => 'This account is disabled.'], 403);
        }

        $device = Device::firstOrNew(['device_uuid' => $data['device_uuid']]);

        if ($device->exists && $device->isRevoked()) {
            return response()->json([
                'code' => 'DEVICE_REVOKED',
                'message' => 'This device has been revoked. Contact the supervisor.',
            ], 403);
        }

        $device->fill([
            'user_id' => $user->id,
            'platform' => $data['platform'] ?? 'android',
            'label' => $data['label'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => CarbonImmutable::now(),
        ])->save();

        // One active token per device.
        $user->tokens()->where('name', 'device:' . $device->device_uuid)->delete();
        $token = $user->createToken('device:' . $device->device_uuid, [$user->role]);

        $this->audit->record('auth.login', $device, null, ['user_id' => $user->id], $user->id);

        return response()->json([
            'token' => $token->plainTextToken,
            'server_time' => CarbonImmutable::now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'specialties' => $user->specialties,
            ],
            'device' => ['id' => $device->id, 'uuid' => $device->device_uuid],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Supervisor revokes a lost or stolen handset. Acceptance criterion: the old
     * token stops working on every protected endpoint within a minute.
     */
    public function revokeDevice(Request $request, Device $device): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'Only the supervisor may revoke a device.'], 403);
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $device->forceFill([
            'revoked_at' => CarbonImmutable::now(),
            'revoked_reason' => $data['reason'] ?? 'revoked by supervisor',
        ])->save();

        $device->user?->tokens()->where('name', 'device:' . $device->device_uuid)->delete();

        $this->audit->record('device.revoked', $device, null, ['reason' => $device->revoked_reason]);

        return response()->json(['status' => 'revoked', 'device_id' => $device->id]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'trade' => $user->trade,
            'specialties' => $user->specialties,
            'shift' => ['start' => $user->shift_start, 'end' => $user->shift_end],
            'server_time' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces device revocation on EVERY protected request, not only at login.
 * Deleting the token is the primary control; this is the second line, because a
 * token issued seconds before revocation must also stop working.
 */
class EnsureDeviceIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Checked on every request, not only at login. A token issued before the
        // account was disabled would otherwise keep working until it expired —
        // and these tokens have no expiry.
        if ($user !== null && ! $user->is_active) {
            return response()->json([
                'code' => 'USER_INACTIVE',
                'message' => 'This account is disabled.',
            ], 403);
        }

        $token = $user?->currentAccessToken();

        // TransientToken (session auth / actingAs) carries no name and is not a
        // device token, so there is nothing to revoke.
        if ($token instanceof PersonalAccessToken && str_starts_with((string) $token->name, 'device:')) {
            $uuid = substr((string) $token->name, strlen('device:'));
            $device = Device::where('device_uuid', $uuid)->first();

            if ($device === null || $device->isRevoked() || $device->user_id !== $user->id
                || $device->approval_status === Device::APPROVAL_REJECTED) {
                return response()->json([
                    'code' => 'DEVICE_REVOKED',
                    'message' => 'This device is no longer authorised.',
                ], 403);
            }

            // Not a wipe: the device may still be approved. Login never issues a
            // token to a pending device, so this is the belt to that brace.
            if ($device->isPendingApproval()) {
                return response()->json([
                    'code' => 'DEVICE_PENDING_APPROVAL',
                    'message' => 'هذا الجهاز بانتظار اعتماد المشرف.',
                ], 403);
            }

            // Presence precision below five minutes has no operational value and
            // writing this row on every API request creates needless contention.
            // The app version reported in X-App-Version rides on the same write.
            if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(5))) {
                $attributes = ['last_seen_at' => now()];
                $version = trim((string) $request->header('X-App-Version', ''));
                if ($version !== '' && mb_strlen($version) <= 32) {
                    $attributes['app_version'] = $version;
                }
                $device->forceFill($attributes)->saveQuietly();
            }
            $request->attributes->set('device', $device);
        }

        return $next($request);
    }
}

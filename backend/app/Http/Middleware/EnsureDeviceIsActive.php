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

            if ($device === null || $device->isRevoked()) {
                return response()->json([
                    'code' => 'DEVICE_REVOKED',
                    'message' => 'This device is no longer authorised.',
                ], 403);
            }

            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
            $request->attributes->set('device', $device);
        }

        return $next($request);
    }
}

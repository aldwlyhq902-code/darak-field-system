<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses technician app builds older than DARAK_MIN_APP_VERSION.
 *
 * The APK is sideloaded, so there is no store to pull a faulty build from. The
 * app already reported X-App-Version on every request, but the server only
 * recorded it. With a minimum set, an older phone gets 426 APP_UPDATE_REQUIRED:
 * the app stops syncing, keeps its queue, and shows the update screen; once the
 * new APK is installed over it, the same queue syncs.
 *
 * Only technicians' devices are gated. Back-office API clients do not send the
 * header and are not the app this protects.
 */
class EnsureSupportedAppVersion
{
    public const CODE = 'APP_UPDATE_REQUIRED';

    public function handle(Request $request, Closure $next): Response
    {
        $minimum = trim((string) config('darak.api.min_app_version', ''));
        if ($minimum === '' || self::parse($minimum) === null || ! $this->isTechnicianApp($request)) {
            return $next($request);
        }

        $reported = trim((string) $request->header('X-App-Version', ''));
        if ($reported !== '' && self::compare($reported, $minimum) >= 0) {
            return $next($request);
        }

        return response()->json([
            'code' => self::CODE,
            'message' => 'نسخة التطبيق قديمة ولم تعد مدعومة. ثبّت النسخة الجديدة من المشرف — عملك محفوظ على الجهاز وسيُرسل بعد التحديث.',
            'min_version' => $minimum,
        ], 426);
    }

    private function isTechnicianApp(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            // Login: only the app sends the header, so only the app is judged.
            return $request->headers->has('X-App-Version');
        }

        $token = $user->currentAccessToken();

        return $user->isTechnician()
            && $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'device:');
    }

    /**
     * -1, 0 or 1. "0.3.0+3" is major.minor.patch then build; a missing build
     * counts as 0. A version that does not parse is older than anything.
     */
    public static function compare(string $reported, string $minimum): int
    {
        $a = self::parse($reported);
        $b = self::parse($minimum);
        if ($a === null) {
            return $b === null ? 0 : -1;
        }
        if ($b === null) {
            return 1;
        }

        return $a <=> $b;
    }

    /** @return list<int>|null */
    private static function parse(string $version): ?array
    {
        if (! preg_match('/\A(\d+)\.(\d+)\.(\d+)(?:\+(\d+))?\z/', trim($version), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0)];
    }
}

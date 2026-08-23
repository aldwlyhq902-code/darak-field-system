<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Baseline browser hardening for both the panel and API responses. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // The public QR form may invoke the phone's native camera through its
        // image input. No other browser surface needs camera access.
        $cameraPolicy = $request->is('report/*') ? 'camera=(self)' : 'camera=()';
        $response->headers->set('Permissions-Policy', "{$cameraPolicy}, microphone=(), geolocation=()");
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; "
            ."object-src 'none'; frame-src https://www.openstreetmap.org; img-src 'self' data:; font-src 'self' data:; "
            ."style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-{$nonce}'",
        );

        // Panel pages carry client, work-order and MFA material. They must not be
        // recoverable from a shared browser's back/forward cache after sign-out.
        if (! $request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($request->is('client*') || $request->is('report*') || $request->is('sales*')) {
            $response->headers->set('Service-Worker-Allowed', '/');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

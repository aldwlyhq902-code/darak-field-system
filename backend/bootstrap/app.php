<?php

use App\Http\Middleware\EnsureBackOfficeRole;
use App\Http\Middleware\EnsureDeviceIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A revoked handset must fail on every protected endpoint, not just login.
        $middleware->alias([
            'device.active' => EnsureDeviceIsActive::class,
            'role.backoffice' => EnsureBackOfficeRole::class,
        ]);

        // Two surfaces, two behaviours. A guest on the panel belongs at the login
        // page; the mobile client needs a clean 401 to know its token died.
        // Returning null for api/* makes the auth middleware throw
        // AuthenticationException instead of redirecting to a route that would not
        // exist for the API — which previously surfaced as a 500.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('panel.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Without this, an unauthenticated API call tries to redirect to a `login`
        // route that does not exist in an API-only app and surfaces as a 500.
        // The mobile client needs a clean 401 to know it must sign in again.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to access this resource.',
                ], 403);
            }

            return null;
        });
    })->create();

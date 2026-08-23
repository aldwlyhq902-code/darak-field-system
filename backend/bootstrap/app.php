<?php

use App\Http\Middleware\EnforcePanelAreaPermission;
use App\Http\Middleware\EnsureBackOfficeRole;
use App\Http\Middleware\EnsureClientPortalUserIsActive;
use App\Http\Middleware\EnsureDeviceIsActive;
use App\Http\Middleware\EnsurePanelPermission;
use App\Http\Middleware\EnsurePanelUserIsActive;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserLocale;
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
        // Vercel maps /api/* to api/index.php and passes the remaining path to
        // PHP. Keep Laravel's normal /api prefix everywhere else.
        apiPrefix: getenv('VERCEL') ? '' : 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetUserLocale::class]);
        $middleware->api(prepend: [SetUserLocale::class]);

        // A revoked handset must fail on every protected endpoint, not just login.
        $middleware->alias([
            'device.active' => EnsureDeviceIsActive::class,
            'role.backoffice' => EnsureBackOfficeRole::class,
            'panel.active' => EnsurePanelUserIsActive::class,
            'panel.permission' => EnsurePanelPermission::class,
            'panel.area' => EnforcePanelAreaPermission::class,
            'two-factor.confirmed' => EnsureTwoFactorConfirmed::class,
            'client.active' => EnsureClientPortalUserIsActive::class,
        ]);

        // Two surfaces, two behaviours. A guest on the panel belongs at the login
        // page; the mobile client needs a clean 401 to know its token died.
        // Returning null for api/* makes the auth middleware throw
        // AuthenticationException instead of redirecting to a route that would not
        // exist for the API — which previously surfaced as a 500.
        $middleware->redirectGuestsTo(
            fn (Request $request) => match (true) {
                $request->is('api/*') => null,
                $request->is('client*') => route('client.login'),
                default => route('panel.login'),
            },
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Serverless providers may truncate long stack traces from the front.
        // Emit one concise root-cause line for operations logs; it is never
        // included in the HTTP response shown to users.
        $exceptions->report(function (Throwable $exception): void {
            if (app()->environment('production')) {
                error_log(sprintf(
                    '[MIHWAR_ERROR] %s: %s',
                    get_debug_type($exception),
                    $exception->getMessage(),
                ));
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || (getenv('VERCEL') && $request->is('v1/*')),
        );

        // Without this, an unauthenticated API call tries to redirect to a `login`
        // route that does not exist in an API-only app and surfaces as a 500.
        // The mobile client needs a clean 401 to know it must sign in again.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || (getenv('VERCEL') && $request->is('v1/*'))) {
                return response()->json([
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || (getenv('VERCEL') && $request->is('v1/*'))) {
                return response()->json([
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to access this resource.',
                ], 403);
            }

            return null;
        });
    })->create();

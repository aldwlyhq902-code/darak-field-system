<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforcePanelAreaPermission;
use App\Http\Middleware\EnsureBackOfficeRole;
use App\Http\Middleware\EnsureClientPortalUserIsActive;
use App\Http\Middleware\EnsureDeviceIsActive;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsurePanelPermission;
use App\Http\Middleware\EnsurePanelUserIsActive;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        // Vercel terminates TLS before forwarding the request to PHP. Trust its
        // proxy headers so Laravel keeps generated form actions and redirects on
        // HTTPS instead of producing mixed-content HTTP URLs in the browser.
        if (getenv('VERCEL')) {
            $middleware->trustProxies(at: '*');
        }

        $middleware->prepend(AssignRequestId::class);
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
            'idempotent' => EnsureIdempotency::class,
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
        $exceptions->context(fn (): array => [
            'request_id' => request()?->attributes->get('request_id'),
        ]);
        // Where an exception came from: the first frame inside app/, which says
        // far more than a line deep in the framework.
        $origin = static function (Throwable $exception): string {
            foreach ([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()] as $frame) {
                if (isset($frame['file']) && str_starts_with((string) $frame['file'], app_path())) {
                    return str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $frame['file']).':'.($frame['line'] ?? 0);
                }
            }

            return basename($exception->getFile()).':'.$exception->getLine();
        };

        // Serverless providers may truncate long stack traces from the front.
        // Emit one concise root-cause line for operations logs; it is never
        // included in the HTTP response shown to users. Class, code, origin and
        // request id only: messages can carry bindings and personal data.
        $exceptions->report(function (Throwable $exception) use ($origin): void {
            if (app()->environment('production')) {
                error_log(sprintf(
                    '[MIHWAR_ERROR] %s code=%s at=%s request=%s',
                    get_debug_type($exception),
                    (string) $exception->getCode(),
                    $origin($exception),
                    (string) request()?->attributes->get('request_id'),
                ));
            }
        });

        // A QueryException message is the SQL WITH its bound values — client
        // names, phones, coordinates. Log what kind and where, never the text,
        // and keep it out of the default stack (which would log the message).
        // Registered after the line above so that line is still written.
        $exceptions->report(function (QueryException $exception) use ($origin): bool {
            Log::error('database.query_failed', [
                'exception' => $exception::class,
                'sqlstate' => $exception->getCode(),
                'connection' => $exception->getConnectionName(),
                'at' => $origin($exception),
                'request_id' => request()?->attributes->get('request_id'),
            ]);

            return false;
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

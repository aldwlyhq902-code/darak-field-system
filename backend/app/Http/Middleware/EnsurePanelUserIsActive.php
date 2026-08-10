<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A disabled back-office account loses the panel on its next click.
 *
 * Deactivating a user deleted their API tokens, which covered the phone — but the
 * panel runs on a cookie session that survived untouched, and the routes checked
 * only `auth:web`. An owner or admin disabled at 9am kept using the dashboard all
 * day. The flag changed and nothing else did.
 */
class EnsurePanelUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('panel.login')
                ->with('err', 'هذا الحساب معطّل. راجع المالك.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office only: owner/supervisor and admin.
 *
 * Applied to inventory writes, warehouse reads and company-wide exports. All of
 * these were reachable with a plain technician token, which meant any field
 * device could invent warehouse stock or export the entire client list.
 */
class EnsureBackOfficeRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || (! $user->isOwner() && ! $user->isAdmin())) {
            return response()->json([
                'code' => 'FORBIDDEN',
                'message' => 'هذه العملية للمشرف والإدارة فقط.',
            ], 403);
        }

        return $next($request);
    }
}

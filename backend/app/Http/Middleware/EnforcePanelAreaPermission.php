<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePanelAreaPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $area = match ($request->segment(1)) {
            'operations', 'board', 'visits', 'maintenance', 'emergencies', 'notifications', 'subcontractors' => 'operations',
            'clients', 'sites', 'client-portal-users' => 'clients',
            'commercial' => 'commercial',
            'finance' => 'finance',
            'inventory', 'procurement' => 'inventory',
            'intelligence' => 'intelligence',
            'team' => 'team',
            'human-resources' => 'hr',
            'fleet' => 'fleet',
            'performance' => 'performance',
            'sales' => 'sales',
            'admin-operations' => 'admin',
            default => null,
        };
        if ($area !== null) {
            abort_unless($request->user()?->canPanel($area), 403);
        }

        return $next($request);
    }
}

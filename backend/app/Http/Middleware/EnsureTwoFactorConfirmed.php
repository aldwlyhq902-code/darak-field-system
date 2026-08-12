<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasConfirmedTwoFactor()) {
            return redirect()->route('panel.two-factor.setup');
        }

        return $next($request);
    }
}

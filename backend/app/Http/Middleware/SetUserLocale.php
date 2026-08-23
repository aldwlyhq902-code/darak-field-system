<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $headerLocale = strtolower(substr($request->header('Accept-Language', ''), 0, 2));
        $defaultLocale = $request->is('api/*') && in_array($headerLocale, self::SUPPORTED, true)
            ? $headerLocale
            : config('app.locale', 'ar');
        $locale = $request->hasSession()
            ? $request->session()->get('locale', $defaultLocale)
            : $defaultLocale;
        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ar');

        return $next($request);
    }
}

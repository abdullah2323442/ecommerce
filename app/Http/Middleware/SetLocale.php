<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bangla is the default language; the visitor can switch to English and the choice is remembered in a
 * cookie (spec ch 28: Bangla and English toggle). Product names are data and are never translated.
 */
class SetLocale
{
    public const SUPPORTED = ['bn', 'en'];

    public const DEFAULT = 'bn';

    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT);

        return $next($request);
    }
}

<?php

namespace LogInsight\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Applies the viewer's chosen language (en / ar) for the current request. */
class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];
    public const RTL = ['ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession() ? $request->session()->get('log-insight.locale') : null;

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    public static function isRtl(): bool
    {
        return in_array(app()->getLocale(), self::RTL, true);
    }
}

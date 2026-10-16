<?php

namespace LogInsight\Security;

use Closure;
use Illuminate\Http\Request;
use LogInsight\LogInsight;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeAccess
{
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        if (! LogInsight::can($request->user(), $ability)) {
            // Not signed in to the built-in login: send them to the form instead of a bare 403.
            if (EnvAuth::enabled() && ! EnvAuth::check() && $request->isMethod('GET')) {
                return redirect()->route('log-insight.login');
            }

            AuditLogger::record('access_denied', $request->path());
            abort(403);
        }

        return $next($request);
    }
}

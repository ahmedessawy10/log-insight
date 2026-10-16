<?php

namespace LogInsight\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController
{
    public function __invoke(Request $request, string $lang): RedirectResponse
    {
        $request->session()->put('log-insight.locale', $lang);

        // Only ever go back to a page inside the viewer (avoid open-redirect via Referer).
        $back = (string) $request->headers->get('referer');
        $prefix = url(config('log-insight.route_prefix'));
        $target = str_starts_with($back, $prefix) ? $back : route('log-insight.dashboard');

        return redirect($target);
    }
}

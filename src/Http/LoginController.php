<?php

namespace LogInsight\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use LogInsight\Security\EnvAuth;

class LoginController
{
    public function show(): View|RedirectResponse
    {
        abort_unless(EnvAuth::enabled(), 404);

        if (EnvAuth::check()) {
            return redirect()->route('log-insight.dashboard');
        }

        return view('log-insight::login', ['misconfigured' => EnvAuth::misconfigured()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(EnvAuth::enabled(), 404);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $result = EnvAuth::attempt($data['username'], $data['password'], (string) $request->ip());

        if ($result['ok']) {
            // Always land on the dashboard: no user-supplied redirect target, so no open redirect.
            return redirect()->route('log-insight.dashboard');
        }

        $message = match ($result['reason']) {
            'throttled' => __('log-insight::messages.login.throttled', ['seconds' => (int) $result['retry_in']]),
            'misconfigured' => __('log-insight::messages.login.misconfigured', ['min' => EnvAuth::MIN_PLAIN_LENGTH]),
            default => __('log-insight::messages.login.invalid'),
        };

        // Never echo the password back; keep the username for convenience.
        return back()->withInput($request->only('username'))->withErrors(['username' => $message]);
    }

    public function destroy(): RedirectResponse
    {
        EnvAuth::logout();

        return redirect()->route('log-insight.login');
    }
}

<?php

namespace LogInsight;

use Closure;
use LogInsight\Security\EnvAuth;

/**
 * Access control entry point.
 *
 *   LogInsight::authUsing(fn ($user, string $ability) => $user?->isAdmin());
 *
 * Abilities: view, view-sensitive, export, manage, audit.
 * Without a callback: allowed in the "local" environment, or for emails in LOG_INSIGHT_ALLOWED_EMAILS.
 * Everything else is denied.
 */
class LogInsight
{
    public const ABILITIES = ['view', 'view-sensitive', 'export', 'manage', 'audit'];

    private static ?Closure $authCallback = null;

    public static function authUsing(Closure $callback): void
    {
        self::$authCallback = $callback;
    }

    public static function flush(): void
    {
        self::$authCallback = null;
    }

    public static function can(mixed $user, string $ability = 'view'): bool
    {
        if (config('log-insight.read_only') && $ability === 'manage') {
            return false;
        }

        // Built-in login: being signed in is the whole check (the env user has every ability).
        if (EnvAuth::enabled()) {
            return EnvAuth::check();
        }

        if (self::$authCallback) {
            return (bool) (self::$authCallback)($user, $ability);
        }

        if (app()->environment('local')) {
            return true;
        }

        $email = $user?->email ?? null;

        return $email !== null && in_array(strtolower($email), array_map('strtolower', config('log-insight.allowed_emails', [])), true);
    }
}

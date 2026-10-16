<?php

namespace LogInsight\Security;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stand-alone login for the viewer: one username + password that live only in .env,
 * independent of the host application's users.
 *
 *   LOG_INSIGHT_USERNAME=admin
 *   LOG_INSIGHT_PASSWORD_HASH='$2y$12$...'      (php artisan log-insight:password)
 *   # or, less safe: LOG_INSIGHT_PASSWORD=a-long-passphrase   (min. 12 characters)
 */
class EnvAuth
{
    public const SESSION_KEY = 'log-insight.auth';
    public const MIN_PLAIN_LENGTH = 12;

    public static function enabled(): bool
    {
        return config('log-insight.auth.mode') === 'env'
            && (string) config('log-insight.auth.credentials.username') !== '';
    }

    /** Credentials exist but are unusable (e.g. no password, or a plain password that is too short). */
    public static function misconfigured(): bool
    {
        $c = config('log-insight.auth.credentials');

        if (! empty($c['password_hash'])) {
            return false;
        }

        return empty($c['password']) || strlen((string) $c['password']) < self::MIN_PLAIN_LENGTH;
    }

    public static function check(): bool
    {
        if (! self::enabled() || ! app()->bound('session.store')) {
            return false;
        }

        $auth = session()->get(self::SESSION_KEY);
        if (! is_array($auth)) {
            return false;
        }

        $now = now()->timestamp;
        $idle = (int) config('log-insight.auth.idle_minutes', 60) * 60;
        $max = (int) config('log-insight.auth.max_hours', 8) * 3600;

        if (($now - ($auth['last'] ?? 0)) > $idle || ($now - ($auth['at'] ?? 0)) > $max
            || ! hash_equals((string) config('log-insight.auth.credentials.username'), (string) ($auth['u'] ?? ''))) {
            session()->forget(self::SESSION_KEY);

            return false;
        }

        $auth['last'] = $now;
        session()->put(self::SESSION_KEY, $auth);

        return true;
    }

    public static function username(): ?string
    {
        return session()->get(self::SESSION_KEY.'.u');
    }

    public static function verify(string $username, string $password): bool
    {
        $c = config('log-insight.auth.credentials');

        // Evaluate both checks unconditionally so timing doesn't reveal which one failed.
        $userOk = hash_equals((string) $c['username'], $username);

        if (! empty($c['password_hash'])) {
            $passOk = Hash::check($password, (string) $c['password_hash']);
        } elseif (! empty($c['password']) && strlen((string) $c['password']) >= self::MIN_PLAIN_LENGTH) {
            $passOk = hash_equals((string) $c['password'], $password);
        } else {
            $passOk = false;
        }

        return $userOk && $passOk;
    }

    /**
     * @return array{ok:bool, reason:?string, retry_in:?int} reason: throttled|invalid|misconfigured
     */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $userKey = 'log-insight-login:'.sha1(strtolower($username).'|'.$ip);
        $ipKey = 'log-insight-login-ip:'.$ip;
        $perUser = (int) config('log-insight.auth.max_attempts', 5);

        foreach ([[$userKey, $perUser], [$ipKey, $perUser * 4]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                AuditLogger::record('login_throttled', mb_substr($username, 0, 60));

                return ['ok' => false, 'reason' => 'throttled', 'retry_in' => RateLimiter::availableIn($key)];
            }
        }

        if (self::misconfigured()) {
            return ['ok' => false, 'reason' => 'misconfigured', 'retry_in' => null];
        }

        if (! self::verify($username, $password)) {
            RateLimiter::hit($userKey, 300);
            RateLimiter::hit($ipKey, 300);
            AuditLogger::record('login_failed', mb_substr($username, 0, 60));

            return ['ok' => false, 'reason' => 'invalid', 'retry_in' => null];
        }

        RateLimiter::clear($userKey);
        session()->regenerate(); // new session id on privilege change (fixation protection)
        session()->put(self::SESSION_KEY, ['u' => $username, 'at' => now()->timestamp, 'last' => now()->timestamp]);
        AuditLogger::record('login_success', $username);

        return ['ok' => true, 'reason' => null, 'retry_in' => null];
    }

    public static function logout(): void
    {
        AuditLogger::record('logout', self::username());
        session()->forget(self::SESSION_KEY);
        session()->regenerateToken();
    }
}

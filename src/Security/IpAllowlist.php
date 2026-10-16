<?php

namespace LogInsight\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('log-insight.allowed_ips', []);

        if ($allowed && ! self::matches((string) $request->ip(), $allowed)) {
            abort(403, 'Your IP address is not allowed to access the log viewer.');
        }

        return $next($request);
    }

    /** Supports exact IPs and CIDR ranges for both IPv4 and IPv6. */
    public static function matches(string $ip, array $allowed): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($allowed as $rule) {
            [$net, $bits] = array_pad(explode('/', $rule, 2), 2, null);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
                continue;
            }

            $bits = $bits === null ? strlen($netBin) * 8 : (int) $bits;
            $fullBytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if (substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
                continue;
            }
            if ($remainder === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}

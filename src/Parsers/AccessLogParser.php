<?php

namespace LogInsight\Parsers;

use Carbon\Carbon;

/**
 * Nginx / Apache "combined" (and "common") access logs:
 *   1.2.3.4 - user [16/Oct/2026:10:00:00 +0000] "GET /path HTTP/1.1" 500 123 "ref" "agent"
 * Status 5xx => error, 4xx => warning, everything else => info.
 */
class AccessLogParser extends LineParser
{
    private const LINE = '/^(\S+) \S+ (\S+) \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?/';

    public function __construct(private readonly string $channel = 'access') {}

    protected function channel(): string
    {
        return $this->channel;
    }

    protected function parseLine(string $line): ?array
    {
        if (! preg_match(self::LINE, $line, $m)) {
            return null;
        }

        $status = (int) $m[6];
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };

        try {
            $time = Carbon::createFromFormat('d/M/Y:H:i:s O', $m[3])->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return [
            'logged_at' => $time,
            'level' => $level,
            'message' => sprintf('%s %s -> %d', $m[4], $m[5], $status),
            'http_method' => $m[4],
            'http_status' => $status,
            'url' => mb_substr($m[5], 0, 500),
            'ip' => $m[1],
            'user_id' => $m[2] !== '-' ? $m[2] : null,
        ];
    }
}

<?php

namespace LogInsight\Parsers;

use Carbon\Carbon;

/** Apache 2.4 error log: [Fri Oct 16 10:00:00.123456 2026] [core:error] [pid 1] [client 1.2.3.4:5] message */
class ApacheErrorParser extends LineParser
{
    private const LEVELS = [
        'emerg' => 'emergency', 'alert' => 'alert', 'crit' => 'critical', 'error' => 'error',
        'warn' => 'warning', 'notice' => 'notice', 'info' => 'info', 'debug' => 'debug',
        'trace1' => 'debug', 'trace2' => 'debug', 'trace3' => 'debug',
    ];

    protected function channel(): string
    {
        return 'apache';
    }

    protected function parseLine(string $line): ?array
    {
        if (! preg_match('/^\[([A-Za-z]{3} [A-Za-z]{3} +\d+ [\d:.]+ \d{4})\] \[(?:\w+:)?(\w+)\](?: \[pid \d+(?::tid \d+)?\])?(?: \[client ([^\]]+)\])? (.*)$/', $line, $m)) {
            return null;
        }

        // "1.2.3.4:5678" -> "1.2.3.4" (IPv6 addresses are left as written)
        $m[3] = preg_replace('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', '$1', $m[3]);

        try {
            $time = Carbon::parse(preg_replace('/\.\d+/', '', $m[1]), config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return [
            'logged_at' => $time,
            'level' => self::LEVELS[strtolower($m[2])] ?? 'error',
            'message' => $m[4],
            'ip' => $m[3] !== '' ? $m[3] : null,
        ];
    }
}

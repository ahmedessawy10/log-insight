<?php

namespace LogInsight\Parsers;

use Carbon\Carbon;

/** Nginx error log: 2026/10/16 10:00:00 [error] 123#0: *1 message, client: 1.2.3.4, ... */
class NginxErrorParser extends LineParser
{
    private const LEVELS = [
        'emerg' => 'emergency', 'alert' => 'alert', 'crit' => 'critical', 'error' => 'error',
        'warn' => 'warning', 'notice' => 'notice', 'info' => 'info', 'debug' => 'debug',
    ];

    protected function channel(): string
    {
        return 'nginx';
    }

    protected function parseLine(string $line): ?array
    {
        if (! preg_match('#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] \d+\#\d+: (?:\*\d+ )?(.*)$#', $line, $m)) {
            return null;
        }

        $ip = preg_match('/client: ([0-9a-fA-F:.]+)/', $m[3], $c) ? $c[1] : null;

        return [
            'logged_at' => Carbon::createFromFormat('Y/m/d H:i:s', $m[1], config('app.timezone')),
            'level' => self::LEVELS[$m[2]] ?? 'error',
            'message' => $m[3],
            'ip' => $ip,
        ];
    }
}

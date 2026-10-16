<?php

namespace LogInsight\Parsers;

use Carbon\Carbon;

/**
 * supervisord.log: 2026-10-16 10:00:00,123 INFO exited: worker (exit status 1; not expected)
 * Lines mentioning FATAL/"not expected"/"gave up" are escalated so crashed workers show as errors.
 */
class SupervisorParser extends LineParser
{
    private const LEVELS = ['CRIT' => 'critical', 'ERRO' => 'error', 'WARN' => 'warning', 'INFO' => 'info', 'DEBG' => 'debug', 'TRAC' => 'debug', 'BLAT' => 'debug'];

    protected function channel(): string
    {
        return 'supervisor';
    }

    protected function parseLine(string $line): ?array
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+ (\w+) (.*)$/', $line, $m)) {
            return null;
        }

        $level = self::LEVELS[substr($m[2], 0, 4)] ?? 'info';
        if ($level === 'info' && preg_match('/FATAL|not expected|gave up/i', $m[3])) {
            $level = 'error';
        }

        return [
            'logged_at' => Carbon::createFromFormat('Y-m-d H:i:s', $m[1], config('app.timezone')),
            'level' => $level,
            'message' => $m[3],
        ];
    }
}

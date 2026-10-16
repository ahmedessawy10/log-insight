<?php

namespace LogInsight\Parsers;

use Carbon\Carbon;
use Generator;

/**
 * Streams a Laravel log file from a byte offset and yields one array per log entry
 * (header line + every following line until the next header, i.e. the stack trace).
 *
 * The final entry in the file may still be mid-write, so it is only yielded when
 * $flushLast is true; otherwise the parser stops *before* it and the caller keeps its offset.
 */
class LaravelLogParser implements LogParser
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w\-]+)\.([A-Za-z]+): (.*)$/';

    /**
     * @return Generator<int, array{offset:int, next_offset:int, logged_at:Carbon, environment:string, channel:?string, level:string, message:string, head:string, context_raw:?string, stack:string}>
     */
    public function parse(string $path, int $offset = 0, bool $flushLast = true): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $current = null;
            $position = $offset;

            while (($line = fgets($handle)) !== false) {
                $lineStart = $position;
                $position += strlen($line);
                $text = rtrim($line, "\r\n");

                if (preg_match(self::HEADER, $text, $m)) {
                    if ($current !== null) {
                        $current['next_offset'] = $lineStart;
                        yield $this->finalize($current);
                    }
                    $current = [
                        'offset' => $lineStart,
                        'next_offset' => $position,
                        'raw_time' => $m[1],
                        'environment' => $m[2],
                        'level' => strtolower($m[3]),
                        'head' => $m[4],
                        'stack_lines' => [],
                    ];
                } elseif ($current !== null) {
                    $current['stack_lines'][] = $text;
                }
                // Lines before the first header (e.g. after a truncated rotation) are skipped.
            }

            if ($current !== null && $flushLast) {
                $current['next_offset'] = $position;
                yield $this->finalize($current);
            }
        } finally {
            fclose($handle);
        }
    }

    private function finalize(array $e): array
    {
        [$message, $context] = $this->splitContext($e['head']);

        return [
            'offset' => $e['offset'],
            'next_offset' => $e['next_offset'],
            'logged_at' => $this->parseTime($e['raw_time']),
            'environment' => $e['environment'],
            'channel' => null,
            'level' => $e['level'],
            'message' => $message,
            'head' => $e['head'],
            'context_raw' => $context,
            'stack' => trim(implode("\n", $e['stack_lines'])),
        ];
    }

    /**
     * Laravel writes "message {json-context} {json-extra}". Peel the trailing JSON off the message.
     *
     * @return array{0:string, 1:?string}
     */
    private function splitContext(string $head): array
    {
        if (preg_match('/^(.*?)\s+(\{.*\}|\[.*\])\s+(\{.*\}|\[\])\s*$/s', $head, $m) && $this->isJson($m[2])) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/^(.*?)\s+(\{.*\})\s*$/s', $head, $m) && $this->isJson($m[2])) {
            return [$m[1], $m[2]];
        }

        // Exceptions: {"exception":"[object] (Class(code: 0): msg at file:line)\n[stacktrace]..."}
        // The JSON spans many lines, so the header line alone is not valid JSON.
        $marker = strpos($head, ' {"exception":"');
        if ($marker !== false) {
            return [substr($head, 0, $marker), null];
        }

        return [$head, null];
    }

    private function isJson(string $s): bool
    {
        json_decode($s);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function parseTime(string $raw): Carbon
    {
        // Laravel's default format has no zone; interpret it in the app timezone.
        return Carbon::parse($raw, config('app.timezone'))->setTimezone(config('app.timezone'));
    }
}

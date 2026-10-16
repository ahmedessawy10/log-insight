<?php

namespace LogInsight\Parsers;

use Generator;

/**
 * Base for single-line formats (Nginx, Apache, Supervisor). Subclasses turn one line into an
 * entry array (or null to skip it); this class handles offsets and streaming.
 */
abstract class LineParser implements LogParser
{
    /** Channel name stored on every entry, e.g. "nginx". */
    abstract protected function channel(): string;

    abstract protected function parseLine(string $line): ?array;

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
            $position = $offset;

            while (($line = fgets($handle)) !== false) {
                $start = $position;
                $position += strlen($line);

                // A line without a newline is still being written; leave it for the next run.
                if (! str_ends_with($line, "\n") && ! $flushLast) {
                    break;
                }

                $parsed = $this->parseLine(rtrim($line, "\r\n"));
                if ($parsed === null) {
                    continue;
                }

                yield $parsed + [
                    'offset' => $start,
                    'next_offset' => $position,
                    'channel' => $this->channel(),
                    'environment' => null,
                    'head' => $parsed['message'],
                    'context_raw' => null,
                    'stack' => '',
                ];
            }
        } finally {
            fclose($handle);
        }
    }
}

<?php

namespace LogInsight\Parsers;

use Generator;

interface LogParser
{
    /**
     * Stream entries from $offset. Each yielded array has at least:
     * offset, next_offset, logged_at (Carbon), environment, channel, level (PSR-3), message, head, context_raw, stack
     * and optionally: http_method, http_status, url, ip.
     *
     * @return Generator<int, array>
     */
    public function parse(string $path, int $offset = 0, bool $flushLast = true): Generator;
}

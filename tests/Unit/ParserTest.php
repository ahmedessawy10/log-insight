<?php

namespace LogInsight\Tests\Unit;

use LogInsight\Parsers\AccessLogParser;
use LogInsight\Parsers\ApacheErrorParser;
use LogInsight\Parsers\LaravelLogParser;
use LogInsight\Parsers\NginxErrorParser;
use LogInsight\Parsers\SupervisorParser;
use LogInsight\Tests\TestCase;

class ParserTest extends TestCase
{
    public function test_laravel_parser_groups_stack_trace_lines_with_their_entry(): void
    {
        $path = $this->writeLog('a.log',
            "[2026-10-15 10:00:00] production.INFO: Hello world\n"
            .$this->exceptionEntry('2026-10-15 10:01:00', 'Kaboom')
            ."[2026-10-15 10:02:00] local.WARNING: Careful {\"user_id\":5} []\n");

        $entries = iterator_to_array((new LaravelLogParser)->parse($path), false);

        $this->assertCount(3, $entries);
        $this->assertSame('info', $entries[0]['level']);
        $this->assertSame('Hello world', $entries[0]['message']);
        $this->assertSame('error', $entries[1]['level']);
        $this->assertSame('Kaboom', $entries[1]['message']);
        $this->assertStringContainsString('#1 {main}', $entries[1]['stack']);
        $this->assertSame('Careful', $entries[2]['message']);
        $this->assertSame('{"user_id":5}', $entries[2]['context_raw']);
        $this->assertSame('local', $entries[2]['environment']);
    }

    public function test_laravel_parser_holds_back_the_last_entry_unless_flushing(): void
    {
        $path = $this->writeLog('a.log', "[2026-10-15 10:00:00] production.INFO: one\n[2026-10-15 10:00:01] production.INFO: two\n");

        $held = iterator_to_array((new LaravelLogParser)->parse($path, 0, false), false);
        $all = iterator_to_array((new LaravelLogParser)->parse($path, 0, true), false);

        $this->assertCount(1, $held);
        $this->assertCount(2, $all);
        // Resuming from the held entry's offset picks up exactly the entry that was held back.
        $resumed = iterator_to_array((new LaravelLogParser)->parse($path, $held[0]['next_offset'], true), false);
        $this->assertSame('two', $resumed[0]['message']);
    }

    public function test_laravel_parser_ignores_garbage_before_the_first_header_and_handles_missing_file(): void
    {
        $path = $this->writeLog('a.log', "orphan stack line\n[2026-10-15 10:00:00] production.ERROR: real\n");
        $entries = iterator_to_array((new LaravelLogParser)->parse($path), false);

        $this->assertCount(1, $entries);
        $this->assertSame('real', $entries[0]['message']);
        $this->assertSame([], iterator_to_array((new LaravelLogParser)->parse($this->logDir.'/nope.log'), false));
    }

    public function test_access_log_maps_status_to_levels(): void
    {
        $path = $this->writeLog('access.log',
            "1.2.3.4 - - [15/Oct/2026:10:00:00 +0000] \"GET /ok HTTP/1.1\" 200 12 \"-\" \"UA\"\n"
            ."1.2.3.4 - bob [15/Oct/2026:10:00:01 +0000] \"POST /missing HTTP/1.1\" 404 12 \"-\" \"UA\"\n"
            ."5.6.7.8 - - [15/Oct/2026:10:00:02 +0000] \"GET /boom HTTP/1.1\" 502 12 \"-\" \"UA\"\n");

        $e = iterator_to_array((new AccessLogParser('nginx'))->parse($path), false);

        $this->assertSame(['info', 'warning', 'error'], array_column($e, 'level'));
        $this->assertSame(502, $e[2]['http_status']);
        $this->assertSame('5.6.7.8', $e[2]['ip']);
        $this->assertSame('/boom', $e[2]['url']);
        $this->assertSame('POST', $e[1]['http_method']);
        $this->assertSame('bob', $e[1]['user_id']);
        $this->assertSame('nginx', $e[0]['channel']);
    }

    public function test_nginx_apache_and_supervisor_error_formats(): void
    {
        $nginx = $this->writeLog('n.log', "2026/10/15 10:00:00 [error] 12#0: *5 connect() failed, client: 9.9.9.9, server: x\n");
        $n = iterator_to_array((new NginxErrorParser)->parse($nginx), false);
        $this->assertSame('error', $n[0]['level']);
        $this->assertSame('9.9.9.9', $n[0]['ip']);

        $apache = $this->writeLog('a.log', "[Thu Oct 15 10:00:00.123456 2026] [core:crit] [pid 1:tid 2] [client 1.1.1.1:80] AH00 something\n");
        $a = iterator_to_array((new ApacheErrorParser)->parse($apache), false);
        $this->assertSame('critical', $a[0]['level']);
        $this->assertSame('1.1.1.1', $a[0]['ip']);

        $sup = $this->writeLog('s.log', "2026-10-15 10:00:00,123 INFO exited: worker (exit status 1; not expected)\n2026-10-15 10:00:01,123 INFO spawned: worker\n");
        $s = iterator_to_array((new SupervisorParser)->parse($sup), false);
        $this->assertSame('error', $s[0]['level'], 'unexpected exits are escalated');
        $this->assertSame('info', $s[1]['level']);
    }

    public function test_line_parser_does_not_consume_a_partially_written_line(): void
    {
        $path = $this->writeLog('s.log', "2026-10-15 10:00:00,123 INFO complete\n2026-10-15 10:00:01,123 INFO partial no newline");
        $e = iterator_to_array((new SupervisorParser)->parse($path, 0, false), false);

        $this->assertCount(1, $e);
    }
}

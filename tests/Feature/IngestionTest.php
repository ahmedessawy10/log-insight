<?php

namespace LogInsight\Tests\Feature;

use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\LogIssue;
use LogInsight\Models\Tag;
use LogInsight\Models\TagRule;
use LogInsight\Tests\TestCase;

class IngestionTest extends TestCase
{
    public function test_ingests_entries_with_exception_details_tags_and_redaction(): void
    {
        $this->useSource('laravel');
        $this->writeLog('laravel.log',
            $this->exceptionEntry('2026-10-15 10:00:00', 'Table users missing', 'Illuminate\\Database\\QueryException', '/app/Repo.php', 42)
            ."[2026-10-15 10:01:00] production.INFO: Login {\"user_id\":9,\"password\":\"hunter2\",\"tags\":[\"auth-flow\"]} []\n");

        $stats = $this->ingest();

        $this->assertSame(['files' => 1, 'entries' => 2, 'more' => false], $stats);

        $ex = LogEntry::where('level', 'error')->firstOrFail();
        $this->assertSame('Illuminate\\Database\\QueryException', $ex->exception_class, 'JSON-escaped backslashes are decoded');
        $this->assertSame('/app/Repo.php', $ex->exception_file);
        $this->assertSame(42, $ex->exception_line);
        $this->assertStringContainsString('#1 {main}', $ex->stack_trace);
        $this->assertStringNotContainsString('"}', $ex->stack_trace);
        $this->assertContains('database', $ex->tags->pluck('name')->all());
        $this->assertContains('production', $ex->tags->pluck('name')->all());

        $info = LogEntry::where('level', 'info')->firstOrFail();
        $this->assertSame('******', $info->context['password']);
        $this->assertSame('9', $info->user_id);
        $this->assertEqualsCanonicalizing(['info', 'production', 'has-user', 'auth-flow'], $info->tags->pluck('name')->all());
    }

    public function test_rerunning_is_idempotent_and_only_reads_appended_entries(): void
    {
        $this->useSource('laravel');
        $path = $this->writeLog('laravel.log', "[2026-10-15 10:00:00] production.INFO: one\n");

        $this->assertSame(1, $this->ingest()['entries']);
        $this->assertSame(0, $this->ingest()['entries'], 'nothing new => nothing ingested');

        $this->writeLog('laravel.log', "[2026-10-15 10:00:05] production.INFO: two\n", append: true);
        $this->assertSame(1, $this->ingest()['entries']);
        $this->assertSame(2, LogEntry::count());
        $this->assertSame(filesize($path), (int) LogFile::first()->last_offset);
    }

    public function test_a_recent_unsettled_last_entry_is_held_back_until_the_file_is_quiet(): void
    {
        config()->set('log-insight.ingest.settle_seconds', 3600);
        $this->useSource('laravel');
        $this->writeLog('laravel.log', "[2026-10-15 10:00:00] production.INFO: one\n[2026-10-15 10:00:01] production.INFO: two\n");

        $this->assertSame(1, $this->ingest()['entries'], 'newest entry might still be mid-write');

        config()->set('log-insight.ingest.settle_seconds', 0);
        $this->assertSame(1, $this->ingest()['entries'], 'picked up once settled');
        $this->assertSame(2, LogEntry::count());
        $this->assertSame(1, LogEntry::where('message', 'two')->count());
    }

    public function test_log_rotation_or_truncation_restarts_from_the_beginning(): void
    {
        $this->useSource('laravel');
        $this->writeLog('laravel.log', "[2026-10-15 10:00:00] production.INFO: a-long-first-entry-with-padding-padding-padding\n[2026-10-15 10:00:01] production.INFO: second-entry-padding-padding\n");
        $this->ingest();

        // New, shorter file with the same name (e.g. after logrotate)
        $this->writeLog('laravel.log', "[2026-10-16 09:00:00] production.ERROR: fresh\n");
        $this->ingest();

        $this->assertTrue(LogEntry::where('message', 'fresh')->exists());
    }

    public function test_same_error_with_different_ids_groups_into_one_issue_and_counts_occurrences(): void
    {
        $this->useSource('laravel');
        $this->writeLog('laravel.log',
            $this->exceptionEntry('2026-10-15 10:00:00', "No query results for model 'User' 11", 'ModelNotFoundException', '/app/A.php', 5)
            .$this->exceptionEntry('2026-10-15 11:00:00', "No query results for model 'User' 987", 'ModelNotFoundException', '/app/A.php', 5)
            .$this->exceptionEntry('2026-10-15 12:00:00', 'Something else', 'RuntimeException', '/app/B.php', 9));
        $this->ingest();

        $this->assertSame(2, LogIssue::count());
        $issue = LogIssue::where('exception_class', 'ModelNotFoundException')->firstOrFail();
        $this->assertSame(2, (int) $issue->occurrences);
        $this->assertSame('2026-10-15 10:00:00', $issue->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 11:00:00', $issue->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, LogEntry::where('issue_id', $issue->id)->count());
    }

    public function test_info_entries_do_not_create_issues(): void
    {
        $this->useSource('laravel');
        $this->writeLog('laravel.log', "[2026-10-15 10:00:00] production.INFO: fine\n[2026-10-15 10:00:01] production.DEBUG: fine\n");
        $this->ingest();

        $this->assertSame(0, LogIssue::count());
    }

    public function test_resolved_issue_that_reappears_is_flagged_as_a_regression(): void
    {
        $this->useSource('laravel');
        $this->writeLog('laravel.log', $this->exceptionEntry('2026-10-15 10:00:00', 'Boom', 'RuntimeException', '/a.php', 1));
        $this->ingest();

        $issue = LogIssue::firstOrFail();
        $issue->update(['status' => 'resolved', 'resolved_at' => '2026-10-15 11:00:00']);

        $this->writeLog('laravel.log', $this->exceptionEntry('2026-10-15 10:30:00', 'Boom', 'RuntimeException', '/a.php', 1), append: true);
        $this->ingest();
        $this->assertSame('resolved', $issue->fresh()->status, 'occurrence before the resolve time is not a regression');

        $this->writeLog('laravel.log', $this->exceptionEntry('2026-10-15 12:00:00', 'Boom', 'RuntimeException', '/a.php', 1), append: true);
        $this->ingest();

        $fresh = $issue->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNotNull($fresh->regressed_at);
    }

    public function test_tag_rules_apply_to_new_entries(): void
    {
        $tag = Tag::create(['name' => 'payment', 'type' => 'rule']);
        TagRule::create(['tag_id' => $tag->id, 'field' => 'message', 'operator' => 'regex', 'value' => 'stripe|paypal']);
        TagRule::create(['tag_id' => $tag->id, 'field' => 'message', 'operator' => 'contains', 'value' => 'refund', 'is_active' => false]);

        $this->useSource('laravel');
        $this->writeLog('laravel.log',
            "[2026-10-15 10:00:00] production.ERROR: Stripe charge failed\n"
            ."[2026-10-15 10:00:01] production.ERROR: refund denied\n");
        $this->ingest();

        $this->assertContains('payment', LogEntry::where('message', 'like', 'Stripe%')->first()->tags->pluck('name')->all());
        $this->assertNotContains('payment', LogEntry::where('message', 'like', 'refund%')->first()->tags->pluck('name')->all(), 'inactive rule is ignored');
    }

    public function test_mixed_sources_use_the_right_parser_per_type(): void
    {
        $nginxDir = $this->logDir.DIRECTORY_SEPARATOR.'n';
        mkdir($nginxDir);
        file_put_contents($nginxDir.'/access.log', "1.2.3.4 - - [15/Oct/2026:10:00:00 +0000] \"GET /x HTTP/1.1\" 500 1 \"-\" \"UA\"\n");
        config()->set('log-insight.sources', [
            ['type' => 'laravel', 'path' => $this->logDir, 'pattern' => '*.log'],
            ['type' => 'nginx_access', 'path' => $nginxDir, 'pattern' => '*.log'],
        ]);
        $this->writeLog('laravel.log', "[2026-10-15 10:00:00] production.INFO: hi\n");

        $this->ingest();

        $this->assertSame('nginx', LogEntry::where('http_status', 500)->firstOrFail()->channel);
        $this->assertSame(2, LogEntry::count());
        unlink($nginxDir.'/access.log');
        rmdir($nginxDir);
    }

    public function test_symlinks_escaping_the_log_directory_are_not_read(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'li-outside-'.bin2hex(random_bytes(3)).'.log';
        file_put_contents($outside, "[2026-10-15 10:00:00] production.ERROR: secret outside file\n");
        $link = $this->logDir.DIRECTORY_SEPARATOR.'evil.log';

        if (! @symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        $this->useSource('laravel');
        $this->ingest();

        $this->assertSame(0, LogEntry::count());
        @unlink($link);
        @unlink($outside);
    }
}

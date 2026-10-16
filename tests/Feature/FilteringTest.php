<?php

namespace LogInsight\Tests\Feature;

use Carbon\Carbon;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\Tag;
use LogInsight\Query\LogQuery;
use LogInsight\Query\QueryLanguageParser;
use LogInsight\Tests\TestCase;

class FilteringTest extends TestCase
{
    private LogFile $file;
    private int $offset = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->file = LogFile::create(['path' => '/x/laravel.log', 'type' => 'laravel']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $level, string $message, string $when, array $tags = [], array $extra = []): LogEntry
    {
        $e = LogEntry::create($extra + [
            'log_file_id' => $this->file->id, 'logged_at' => $when, 'level' => $level, 'message' => $message,
            'channel' => 'daily', 'environment' => 'production', 'byte_offset' => $this->offset++,
        ]);
        foreach ($tags as $t) {
            $e->tags()->attach(Tag::firstOrCreate(['name' => $t])->id);
        }

        return $e;
    }

    private function messages(LogQuery $q): array
    {
        return $q->apply(LogEntry::query())->orderBy('id')->pluck('message')->all();
    }

    public function test_level_text_and_exception_filters(): void
    {
        $this->entry('error', 'Payment failed', '2026-10-15 10:00:00', extra: ['exception_class' => 'App\\PaymentException']);
        $this->entry('info', 'Payment ok', '2026-10-15 10:01:00');
        $this->entry('error', 'Disk full', '2026-10-15 10:02:00');

        $this->assertSame(['Payment failed', 'Disk full'], $this->messages(LogQuery::fromArray(['levels' => ['error']])));
        $this->assertSame(['Payment failed', 'Payment ok'], $this->messages(LogQuery::fromArray(['search' => 'payment'])));
        $this->assertSame(['Payment failed'], $this->messages(LogQuery::fromArray(['hasException' => true])));
        $this->assertSame(['Payment failed'], $this->messages(LogQuery::fromArray(['exception' => 'PaymentExc'])));
    }

    public function test_like_wildcards_in_search_are_literal(): void
    {
        $this->entry('info', '100% done', '2026-10-15 10:00:00');
        $this->entry('info', '100 done', '2026-10-15 10:01:00');

        $this->assertSame(['100% done'], $this->messages(LogQuery::fromArray(['search' => '100%'])));
    }

    public function test_tag_any_all_and_exclude(): void
    {
        $this->entry('error', 'a', '2026-10-15 10:00:00', ['payment', 'stripe']);
        $this->entry('error', 'b', '2026-10-15 10:00:01', ['payment']);
        $this->entry('error', 'c', '2026-10-15 10:00:02', ['stripe', 'known-bug']);
        $this->entry('error', 'd', '2026-10-15 10:00:03');

        $this->assertSame(['a', 'b', 'c'], $this->messages(LogQuery::fromArray(['tagsAny' => ['payment', 'stripe']])));
        $this->assertSame(['a'], $this->messages(LogQuery::fromArray(['tagsAll' => ['payment', 'stripe']])));
        $this->assertSame(['a', 'b', 'd'], $this->messages(LogQuery::fromArray(['tagsNone' => ['known-bug']])));
        $this->assertSame(['a'], $this->messages(LogQuery::fromArray(['tagsAny' => ['stripe'], 'tagsNone' => ['known-bug']])));
    }

    public function test_date_presets_and_custom_ranges(): void
    {
        $this->entry('error', 'now-ish', '2026-10-15 11:50:00');
        $this->entry('error', 'earlier today', '2026-10-15 03:00:00');
        $this->entry('error', 'yesterday', '2026-10-14 22:00:00');
        $this->entry('error', 'last month', '2026-09-20 10:00:00');

        $this->assertSame(['now-ish'], $this->messages(LogQuery::fromArray(['preset' => '15m'])));
        $this->assertSame(['now-ish', 'earlier today'], $this->messages(LogQuery::fromArray(['preset' => 'today'])));
        $this->assertSame(['yesterday'], $this->messages(LogQuery::fromArray(['preset' => 'yesterday'])));
        $this->assertSame(['now-ish', 'earlier today', 'yesterday'], $this->messages(LogQuery::fromArray(['preset' => 'month'])));
        $this->assertSame(['last month'], $this->messages(LogQuery::fromArray(['preset' => 'last_month'])));
        // A bare end date includes the whole day.
        $this->assertSame(['yesterday'], $this->messages(LogQuery::fromArray(['from' => '2026-10-14', 'to' => '2026-10-14'])));
        $this->assertSame(['earlier today'], $this->messages(LogQuery::fromArray(['from' => '2026-10-15 01:00', 'to' => '2026-10-15 05:00'])));
    }

    public function test_regex_search_works_and_bad_or_overlong_patterns_match_nothing(): void
    {
        $this->entry('error', 'Timeout after 30s', '2026-10-15 10:00:00');
        $this->entry('error', 'timed out', '2026-10-15 10:00:01');
        $this->entry('error', 'Other', '2026-10-15 10:00:02');

        $this->assertSame(['Timeout after 30s', 'timed out'], $this->messages(LogQuery::fromArray(['search' => 'time(out|d out)', 'regex' => true])));
        $this->assertSame([], $this->messages(LogQuery::fromArray(['search' => '(unclosed', 'regex' => true])));
        $this->assertSame([], $this->messages(LogQuery::fromArray(['search' => str_repeat('a', 150), 'regex' => true])));
    }

    public function test_search_in_stack_trace_is_opt_in(): void
    {
        $this->entry('error', 'Generic', '2026-10-15 10:00:00', extra: ['stack_trace' => '#0 StripeGateway->charge()']);

        $this->assertSame([], $this->messages(LogQuery::fromArray(['search' => 'StripeGateway'])));
        $this->assertSame(['Generic'], $this->messages(LogQuery::fromArray(['search' => 'StripeGateway', 'inStack' => true])));
    }

    public function test_http_status_filters(): void
    {
        $this->entry('error', 'e500', '2026-10-15 10:00:00', extra: ['http_status' => 500, 'http_method' => 'GET', 'ip' => '1.1.1.1']);
        $this->entry('warning', 'e404', '2026-10-15 10:00:01', extra: ['http_status' => 404, 'http_method' => 'POST', 'ip' => '2.2.2.2']);
        $this->entry('info', 'e200', '2026-10-15 10:00:02', extra: ['http_status' => 200]);

        $this->assertSame(['e500'], $this->messages(LogQuery::fromArray(['httpStatus' => '5xx'])));
        $this->assertSame(['e500', 'e404'], $this->messages(LogQuery::fromArray(['httpStatus' => '4xx,5xx'])));
        $this->assertSame(['e404'], $this->messages(LogQuery::fromArray(['httpStatus' => '404'])));
        $this->assertSame(['e404'], $this->messages(LogQuery::fromArray(['httpMethod' => 'post'])));
        $this->assertSame(['e500'], $this->messages(LogQuery::fromArray(['ip' => '1.1.1.1'])));
    }

    public function test_query_language_builds_the_same_filters(): void
    {
        $this->entry('error', 'Payment failed', '2026-10-15 10:00:00', ['payment'], ['exception_class' => 'App\\PaymentException', 'user_id' => '42']);
        $this->entry('error', 'Payment retry', '2026-10-15 10:01:00', ['payment', 'known-bug'], ['user_id' => '42']);
        $this->entry('warning', 'Payment slow', '2026-10-15 10:02:00', ['payment'], ['user_id' => '7']);
        $this->entry('error', 'Old one', '2026-09-01 10:00:00', ['payment']);

        $parse = fn (string $s) => (new QueryLanguageParser)->parse($s);

        $this->assertSame(['Payment failed', 'Payment retry'], $this->messages($parse('level:error tag:payment user:42 after:2026-10-01')));
        $this->assertSame(['Payment failed'], $this->messages($parse('level:error,critical tag:payment -tag:known-bug after:2026-10-01')));
        $this->assertSame(['Payment failed'], $this->messages($parse('has:exception exception:PaymentException')));
        $this->assertSame(['Payment retry'], $this->messages($parse('"Payment retry"')));
        $this->assertSame(['Payment failed', 'Payment retry', 'Payment slow'], $this->messages($parse('/pay(ment)? (failed|retry|slow)/ after:2026-10-01')));
        $this->assertSame(['Old one'], $this->messages($parse('before:2026-09-30')));

        $q = $parse('level:error,bogus tag:a,b -tag:c http:5xx has:exception "two words"');
        $this->assertSame(['error'], $q->levels, 'unknown levels are dropped');
        $this->assertSame(['a', 'b'], $q->tagsAny);
        $this->assertSame(['c'], $q->tagsNone);
        $this->assertSame('5xx', $q->httpStatus);
        $this->assertTrue($q->hasException);
        $this->assertSame('two words', $q->search);
    }
}

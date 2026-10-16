<?php

namespace LogInsight\Tests\Feature;

use Carbon\Carbon;
use LogInsight\Metrics\MetricsService;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\LogIssue;
use LogInsight\Tests\TestCase;

class MetricsTest extends TestCase
{
    private LogFile $file;
    private int $offset = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 2026-10-15 12:00 -> week starts Monday 2026-10-12, previous week 10-05..10-11
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->file = LogFile::create(['path' => '/x/laravel.log', 'type' => 'laravel']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function add(string $level, string $when, int $times = 1, ?int $issue = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            LogEntry::create([
                'log_file_id' => $this->file->id, 'logged_at' => $when, 'level' => $level, 'message' => 'm',
                'byte_offset' => $this->offset++, 'issue_id' => $issue,
            ]);
        }
    }

    public function test_today_week_and_month_counts_with_percent_change(): void
    {
        $this->add('error', '2026-10-15 09:00:00', 6);   // today
        $this->add('critical', '2026-10-15 10:00:00', 2); // today (counts as error)
        $this->add('info', '2026-10-15 10:00:00', 50);    // not an error
        $this->add('error', '2026-10-14 09:00:00', 4);   // yesterday (same week)
        $this->add('error', '2026-10-14 11:00:00', 4);   // yesterday, before 12:00 => in comparison window
        $this->add('error', '2026-10-14 20:00:00', 9);   // yesterday evening => after "same time" cut-off
        $this->add('error', '2026-10-08 09:00:00', 4);   // last week
        $this->add('error', '2026-09-10 09:00:00', 10);  // last month (before the same day-of-month cut-off)

        $k = app(MetricsService::class)->kpis();

        $this->assertSame(8, $k['today']['count']);
        $this->assertSame(8, $k['today']['previous'], 'yesterday up to the same time of day');
        $this->assertSame(0.0, $k['today']['change_percent']);

        $this->assertSame(8 + 4 + 4 + 9, $k['week']['count']);
        $this->assertSame(4, $k['week']['previous']);
        $this->assertSame(525.0, $k['week']['change_percent']);

        $this->assertSame(8 + 4 + 4 + 9 + 4, $k['month']['count']);
        $this->assertSame(10, $k['month']['previous']);
    }

    public function test_change_percent_handles_zero_baseline(): void
    {
        $m = app(MetricsService::class);

        $this->assertNull($m->change(5, 0), 'cannot express growth from zero');
        $this->assertSame(0.0, $m->change(0, 0));
        $this->assertSame(-50.0, $m->change(5, 10));
    }

    public function test_critical_new_open_and_regression_counters(): void
    {
        $this->add('critical', '2026-10-15 08:00:00');
        $this->add('alert', '2026-10-15 08:00:00');
        $this->add('error', '2026-10-15 08:00:00');
        LogIssue::create(['fingerprint' => 'a', 'title' => 'new today', 'level' => 'error', 'first_seen_at' => '2026-10-15 08:00:00', 'last_seen_at' => '2026-10-15 08:00:00', 'occurrences' => 1]);
        LogIssue::create(['fingerprint' => 'b', 'title' => 'old', 'level' => 'error', 'first_seen_at' => '2026-10-01 08:00:00', 'status' => 'resolved']);
        LogIssue::create(['fingerprint' => 'c', 'title' => 'came back', 'level' => 'error', 'first_seen_at' => '2026-10-01 08:00:00', 'regressed_at' => '2026-10-14 08:00:00']);

        $k = app(MetricsService::class)->kpis();

        $this->assertSame(2, $k['critical_today']);
        $this->assertSame(1, $k['new_issues_today']);
        $this->assertSame(2, $k['open_issues']);
        $this->assertSame(1, $k['regressions']);
    }

    public function test_timeline_fills_gaps_and_splits_by_level(): void
    {
        $this->add('error', '2026-10-13 10:00:00', 2);
        $this->add('critical', '2026-10-13 18:00:00', 1);
        $this->add('error', '2026-10-15 01:00:00', 3);

        $t = app(MetricsService::class)->timeline(Carbon::parse('2026-10-12 00:00:00'), Carbon::parse('2026-10-15 12:00:00'), 'day');

        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'], $t['labels']);
        $this->assertSame([0, 3, 0, 3], $t['totals']);
        $this->assertSame([0, 2, 0, 3], $t['series']['error']);
        $this->assertSame([0, 1, 0, 0], $t['series']['critical']);

        $h = app(MetricsService::class)->timeline(Carbon::parse('2026-10-15 00:00:00'), Carbon::parse('2026-10-15 03:00:00'), 'hour');
        $this->assertSame(['2026-10-15 00', '2026-10-15 01', '2026-10-15 02', '2026-10-15 03'], $h['labels']);
        $this->assertSame([0, 3, 0, 0], $h['totals']);
    }

    public function test_top_issues_rank_by_occurrences_inside_the_period(): void
    {
        $a = LogIssue::create(['fingerprint' => 'a', 'title' => 'A', 'level' => 'error', 'occurrences' => 500]);
        $b = LogIssue::create(['fingerprint' => 'b', 'title' => 'B', 'level' => 'error', 'occurrences' => 3]);
        $this->add('error', '2026-10-15 09:00:00', 1, $a->id);
        $this->add('error', '2026-10-15 09:00:00', 4, $b->id);
        $this->add('error', '2026-09-01 09:00:00', 400, $a->id); // outside the period

        $top = app(MetricsService::class)->topIssues(Carbon::parse('2026-10-15 00:00:00'), Carbon::parse('2026-10-15 23:59:59'));

        $this->assertSame(['B', 'A'], $top->pluck('title')->all());
        $this->assertSame([4, 1], $top->pluck('period_count')->all());
    }
}

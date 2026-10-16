<?php

namespace LogInsight\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use LogInsight\Ingestion\Ingestor;
use LogInsight\Livewire\Dashboard;
use LogInsight\Livewire\IssueList;
use LogInsight\Livewire\LogBrowser;
use LogInsight\LogInsight;
use LogInsight\Models\LogEntry;
use LogInsight\Tests\TestCase;

class LiveRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSource('laravel');
    }

    private function line(int $n): string
    {
        return sprintf("[2026-10-15 10:%02d:00] production.INFO: entry-%d\n", $n, $n);
    }

    public function test_opening_a_page_reads_new_log_lines_without_the_scheduler(): void
    {
        $this->writeLog('laravel.log', $this->line(1).$this->line(2).$this->line(3));
        $this->assertSame(0, LogEntry::count());

        foreach ([Dashboard::class, LogBrowser::class, IssueList::class] as $i => $component) {
            Cache::flush();
            LogEntry::query()->delete();
            \LogInsight\Models\LogFile::query()->delete();

            $c = Livewire::test($component);

            $this->assertSame(3, LogEntry::count(), "$component should ingest on mount");
            $c->assertSet('newEntries', 3);
            $this->assertNotNull($c->get('lastRefreshed'));
        }
    }

    public function test_the_logs_page_shows_the_entries_it_just_ingested(): void
    {
        $this->writeLog('laravel.log', $this->line(1).$this->line(2));

        Livewire::test(LogBrowser::class)->assertSee('entry-1')->assertSee('entry-2');
    }

    public function test_opening_again_within_the_interval_does_not_reread_but_refresh_button_does(): void
    {
        $this->writeLog('laravel.log', $this->line(1).$this->line(2));
        Livewire::test(Dashboard::class);
        $this->assertSame(2, LogEntry::count());

        $this->writeLog('laravel.log', $this->line(3).$this->line(4), append: true);

        $second = Livewire::test(Dashboard::class);
        $this->assertSame(2, LogEntry::count(), 'automatic refreshes are throttled');

        $this->travel(3)->seconds(); // past the double-click guard, still inside the 10s auto-throttle
        $second->call('refreshNow');
        $this->assertSame(4, LogEntry::count(), 'the Refresh button bypasses the throttle');
        $second->assertSet('newEntries', 2);
    }

    public function test_the_double_click_guard_stops_rapid_repeat_refreshes(): void
    {
        $this->writeLog('laravel.log', $this->line(1).$this->line(2));
        $c = Livewire::test(Dashboard::class)->call('refreshNow');
        $this->writeLog('laravel.log', $this->line(3).$this->line(4), append: true);

        $c->call('refreshNow');

        $this->assertSame(2, LogEntry::count());
    }

    public function test_live_mode_polls_for_new_entries(): void
    {
        $this->writeLog('laravel.log', $this->line(1).$this->line(2));
        $c = Livewire::test(LogBrowser::class)->set('live', true);

        $this->writeLog('laravel.log', $this->line(3).$this->line(4), append: true);
        $this->travel(11)->seconds();
        $c->call('poll')->assertSee('entry-3');

        $this->assertSame(4, LogEntry::count());
    }

    public function test_a_big_backlog_is_read_in_bounded_slices_and_never_throttled_until_caught_up(): void
    {
        config()->set('log-insight.ingest.max_entries_on_open', 4);
        $this->writeLog('laravel.log', implode('', array_map(fn ($n) => $this->line($n), range(1, 11))));

        $c = Livewire::test(Dashboard::class);
        $this->assertSame(4, LogEntry::count());
        $c->assertSet('refreshMore', true);

        $this->travel(3)->seconds();
        $c->call('refreshNow');
        $this->assertSame(8, LogEntry::count());

        $this->travel(3)->seconds();
        $c->call('refreshNow');
        $this->assertSame(11, LogEntry::count());
        $c->assertSet('refreshMore', false);
        $this->assertSame(11, LogEntry::distinct('byte_offset')->count(), 'no entry was read twice or skipped');
    }

    public function test_it_can_be_switched_off(): void
    {
        config()->set('log-insight.ingest.on_open', false);
        $this->writeLog('laravel.log', $this->line(1));

        Livewire::test(Dashboard::class)->call('refreshNow');

        $this->assertSame(0, LogEntry::count());
    }

    public function test_a_failing_log_source_does_not_break_the_page(): void
    {
        $this->writeLog('laravel.log', $this->line(1));
        $this->app->instance(Ingestor::class, new class($this->app->make(\LogInsight\Processing\Redactor::class), $this->app->make(\LogInsight\Processing\Fingerprinter::class), $this->app->make(\LogInsight\Processing\Tagger::class)) extends Ingestor {
            public function run(?int $maxEntries = null): array
            {
                throw new \RuntimeException('disk exploded');
            }
        });
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        })->stop();

        // The page still renders with whatever data is already stored...
        Livewire::test(Dashboard::class)->assertOk()->assertSee('Dashboard');
        $this->assertSame(0, LogEntry::count());

        // ...and the failure is reported (not thrown) and backs off instead of retrying on every request.
        Cache::flush();
        $result = app(\LogInsight\Ingestion\LiveRefresh::class)->refresh();
        $this->assertSame(['ran' => false, 'entries' => 0, 'more' => false, 'error' => true], $result);
        $this->assertSame(['disk exploded'], $reported);
        $this->assertFalse(app(\LogInsight\Ingestion\LiveRefresh::class)->refresh()['error'], 'throttled after a failure');
    }

    public function test_it_skips_quietly_when_another_ingestion_holds_the_lock(): void
    {
        $this->writeLog('laravel.log', $this->line(1));
        $lock = Cache::lock('log-insight:ingest', 60);
        $this->assertTrue($lock->get());

        Livewire::test(Dashboard::class)->assertOk();

        $this->assertSame(0, LogEntry::count());
        $lock->release();
    }

    public function test_unauthorised_visitors_cannot_trigger_ingestion(): void
    {
        $this->app['env'] = 'production';
        $this->writeLog('laravel.log', $this->line(1));

        Livewire::test(Dashboard::class)->assertForbidden();
        Livewire::test(LogBrowser::class)->assertForbidden();

        $this->assertSame(0, LogEntry::count(), 'access is checked before any file is read');
    }

    public function test_refresh_and_poll_actions_are_authorised_too(): void
    {
        $this->writeLog('laravel.log', $this->line(1));
        $component = Livewire::test(Dashboard::class); // allowed in "local"
        LogEntry::query()->delete();
        \LogInsight\Models\LogFile::query()->delete();
        Cache::flush();

        $snapshot = $component->snapshot ?? null;
        LogInsight::authUsing(fn () => false);
        $component->call('refreshNow')->assertForbidden();
        // (a component that got a 403 can't take another call, so poll gets a fresh one)
        LogInsight::flush();
        $second = Livewire::test(Dashboard::class);
        LogEntry::query()->delete();
        \LogInsight\Models\LogFile::query()->delete();
        Cache::flush();
        LogInsight::authUsing(fn () => false);
        $second->call('poll')->assertForbidden();

        $this->assertSame(0, LogEntry::count());
    }
}

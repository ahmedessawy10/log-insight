<?php

namespace LogInsight\Ingestion;

use Illuminate\Support\Facades\Cache;

/**
 * Reads new log lines when someone opens (or refreshes) the viewer, so the data is current
 * without waiting for the scheduler. Safe to call often:
 *   - throttled (min_interval seconds between runs, unless forced),
 *   - never overlaps another run (lock shared with `log-insight:ingest`),
 *   - bounded (max_entries per call), so a big backlog can't freeze the page,
 *   - never throws: a broken log file must not break the page that is showing existing data.
 */
class LiveRefresh
{
    public function __construct(private readonly Ingestor $ingestor) {}

    /**
     * @return array{ran:bool, entries:int, more:bool, error:bool}
     */
    public function refresh(bool $force = false): array
    {
        $result = ['ran' => false, 'entries' => 0, 'more' => false, 'error' => false];

        if (! config('log-insight.ingest.on_open', true)) {
            return $result;
        }

        // Automatic refreshes (page open, live mode) share a throttle; the Refresh button only has a short
        // anti-double-click guard so it always works when someone actually wants fresh data.
        $throttleKey = 'log-insight:live-refresh';
        $forceKey = 'log-insight:live-refresh:force';
        $interval = (int) config('log-insight.ingest.min_interval', 10);
        $forceInterval = (int) config('log-insight.ingest.force_min_interval', 2);

        $guardKey = $force ? $forceKey : $throttleKey;
        if (($force ? $forceInterval : $interval) > 0 && Cache::has($guardKey)) {
            return $result;
        }

        $lock = Cache::lock('log-insight:ingest', 900);
        if (! $lock->get()) {
            return $result; // the scheduler (or another viewer) is already ingesting
        }

        try {
            $stats = $this->ingestor->run((int) config('log-insight.ingest.max_entries_on_open', 2000));
            $result = ['ran' => true, 'entries' => $stats['entries'], 'more' => $stats['more'], 'error' => false];
            // If we stopped early, don't throttle: the next refresh should continue straight away.
            if (! $stats['more']) {
                Cache::put($throttleKey, true, now()->addSeconds(max(1, $interval)));
            }
            Cache::put($forceKey, true, now()->addSeconds(max(1, $forceInterval)));
        } catch (\Throwable $e) {
            report($e);
            $result['error'] = true;
            Cache::put($throttleKey, true, now()->addSeconds(30)); // back off after a failure
        } finally {
            $lock->release();
        }

        return $result;
    }
}

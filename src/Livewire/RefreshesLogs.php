<?php

namespace LogInsight\Livewire;

use LogInsight\Ingestion\LiveRefresh;

/**
 * Makes a page read new log lines when it is opened, when "Refresh" is clicked, and (in live mode)
 * on a timer. Needs GuardsAccess on the same component.
 */
trait RefreshesLogs
{
    public bool $live = false;
    public ?string $lastRefreshed = null;
    public int $newEntries = 0;
    public bool $refreshMore = false;

    protected function refreshLogs(bool $force = false): void
    {
        $result = app(LiveRefresh::class)->refresh($force);

        if ($result['ran']) {
            $this->lastRefreshed = now()->format('H:i:s');
            $this->newEntries = $result['entries'];
            $this->refreshMore = $result['more'];
        } elseif ($this->lastRefreshed === null) {
            // Throttled on first open: the data was refreshed moments ago by someone/something else.
            $this->lastRefreshed = now()->format('H:i:s');
        }
    }

    public function refreshNow(): void
    {
        $this->authorizeAbility('view');
        $this->refreshLogs(true);
    }

    /** Called by wire:poll while live mode is on. */
    public function poll(): void
    {
        $this->authorizeAbility('view');
        $this->refreshLogs();
    }

    public function pollSeconds(): int
    {
        return max(3, (int) config('log-insight.ingest.poll_seconds', 10));
    }
}

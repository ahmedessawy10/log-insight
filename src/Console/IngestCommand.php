<?php

namespace LogInsight\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use LogInsight\Alerts\AlertEvaluator;
use LogInsight\Ingestion\Ingestor;

class IngestCommand extends Command
{
    protected $signature = 'log-insight:ingest';

    protected $description = 'Read new entries from the configured log files into the database';

    public function handle(Ingestor $ingestor, AlertEvaluator $alerts): int
    {
        if (! config('log-insight.enabled')) {
            $this->warn('Log Insight is disabled.');

            return self::SUCCESS;
        }

        // Two overlapping runs would read the same bytes; the lock makes the second one skip.
        $lock = Cache::lock('log-insight:ingest', 900);
        if (! $lock->get()) {
            $this->warn('Another ingestion run is in progress; skipping.');

            return self::SUCCESS;
        }

        try {
            $stats = $ingestor->run();
        } finally {
            $lock->release();
        }

        $this->info("Scanned {$stats['files']} file(s), ingested {$stats['entries']} new entr".($stats['entries'] === 1 ? 'y' : 'ies').'.');

        if (config('log-insight.alerts.enabled')) {
            $sent = $alerts->evaluate();
            if ($sent) {
                $this->info("Sent $sent alert(s).");
            }
        }

        return self::SUCCESS;
    }
}

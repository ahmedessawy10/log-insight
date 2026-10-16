<?php

namespace LogInsight\Console;

use Illuminate\Console\Command;
use LogInsight\Models\AuditLog;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogIssue;

class PruneCommand extends Command
{
    protected $signature = 'log-insight:prune {--days= : Override retention_days}';

    protected $description = 'Delete stored entries (and stale issues) older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('log-insight.retention_days', 90));
        $cutoff = now()->subDays($days);

        $entries = LogEntry::where('logged_at', '<', $cutoff)->delete();
        // Issues with no remaining entries and not seen within the retention window are noise.
        $issues = LogIssue::where('last_seen_at', '<', $cutoff)->whereDoesntHave('entries')->delete();
        $audit = AuditLog::where('created_at', '<', now()->subDays(max($days, 365)))->delete();

        $this->info("Pruned $entries entries, $issues issues, $audit audit rows older than $days days.");

        return self::SUCCESS;
    }
}

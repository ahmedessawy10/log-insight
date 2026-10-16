<?php

namespace LogInsight\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'log-insight:install {--no-migrate : Skip running migrations}';

    protected $description = 'Publish the config, run migrations and perform the first ingestion';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'log-insight-config']);
        $this->info('Published config/log-insight.php');

        if (! $this->option('no-migrate')) {
            $this->call('migrate');
        }

        $this->call('log-insight:ingest');

        $this->newLine();
        $this->info('Log Insight is ready: '.url(config('log-insight.route_prefix')));
        $this->line('Access is denied outside the "local" environment until you set LOG_INSIGHT_ALLOWED_EMAILS');
        $this->line('or call LogInsight::authUsing(...) in a service provider.');

        return self::SUCCESS;
    }
}

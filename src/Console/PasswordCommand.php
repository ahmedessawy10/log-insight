<?php

namespace LogInsight\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use LogInsight\Security\EnvAuth;

use function Laravel\Prompts\password;

class PasswordCommand extends Command
{
    protected $signature = 'log-insight:password';

    protected $description = 'Generate the .env line for the viewer login password (stores a hash, not the password)';

    public function handle(): int
    {
        $plain = password('Choose a password for the Log Insight login', required: true,
            validate: fn (string $v) => strlen($v) < EnvAuth::MIN_PLAIN_LENGTH
                ? 'Use at least '.EnvAuth::MIN_PLAIN_LENGTH.' characters (a passphrase works well).' : null);

        $hash = Hash::make($plain);

        $this->newLine();
        $this->line('Add these to your .env (the single quotes are required because the hash contains "$"):');
        $this->newLine();
        $this->line('LOG_INSIGHT_USERNAME=admin');
        $this->line("LOG_INSIGHT_PASSWORD_HASH='{$hash}'");
        $this->newLine();
        $this->comment('Then run: php artisan config:clear');

        return self::SUCCESS;
    }
}

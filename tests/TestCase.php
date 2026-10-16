<?php

namespace LogInsight\Tests;

use Livewire\LivewireServiceProvider;
use LogInsight\LogInsight;
use LogInsight\LogInsightServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'li-test-'.bin2hex(random_bytes(4));
        mkdir($this->logDir, 0777, true);

        parent::setUp();

        LogInsight::flush();
    }

    protected function tearDown(): void
    {
        // Rolling back migrations in "production" would prompt for confirmation.
        $this->app['env'] = 'local';

        foreach (glob($this->logDir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, LogInsightServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Default-deny applies outside "local"; tests that exercise it switch to production explicitly.
        $app['env'] = 'local';
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('log-insight.middleware', ['web']);
        $app['config']->set('log-insight.route_prefix', 'log-insight');
        $app['config']->set('log-insight.ingest.settle_seconds', 0);
        $app['config']->set('log-insight.sources', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function useSource(string $type, string $pattern = '*.log'): void
    {
        config()->set('log-insight.sources', [['type' => $type, 'path' => $this->logDir, 'pattern' => $pattern]]);
    }

    protected function writeLog(string $name, string $contents, bool $append = false): string
    {
        $path = $this->logDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents, $append ? FILE_APPEND : 0);
        clearstatcache();

        return $path;
    }

    protected function ingest(): array
    {
        return $this->app->make(\LogInsight\Ingestion\Ingestor::class)->run();
    }

    /** A Laravel-format exception entry, as written by Monolog's LineFormatter (JSON spans lines). */
    protected function exceptionEntry(string $time, string $message = 'Boom', string $class = 'RuntimeException', string $file = '/app/Foo.php', int $line = 10): string
    {
        $classEsc = str_replace('\\', '\\\\', $class);

        return "[$time] production.ERROR: $message {\"exception\":\"[object] ($classEsc(code: 0): $message at $file:$line)\n"
            ."[stacktrace]\n#0 $file($line): App\\\\Foo->bar()\n#1 {main}\n\"} \n";
    }
}

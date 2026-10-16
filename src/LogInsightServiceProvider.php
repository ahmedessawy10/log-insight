<?php

namespace LogInsight;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use LogInsight\Console\IngestCommand;
use LogInsight\Console\InstallCommand;
use LogInsight\Console\PruneCommand;
use LogInsight\Console\RetagCommand;
use LogInsight\Livewire\AuditViewer;
use LogInsight\Livewire\Dashboard;
use LogInsight\Livewire\IssueList;
use LogInsight\Livewire\LogBrowser;
use LogInsight\Livewire\TagManager;
use LogInsight\Processing\Fingerprinter;
use LogInsight\Processing\Redactor;
use LogInsight\Processing\Tagger;
use LogInsight\Console\PasswordCommand;
use LogInsight\Security\AuthorizeAccess;
use LogInsight\Security\EnvAuth;
use LogInsight\Security\IpAllowlist;
use LogInsight\Security\SecurityHeaders;
use LogInsight\Support\SetLocale;

class LogInsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-insight.php', 'log-insight');

        $this->app->bind(Redactor::class, fn () => Redactor::fromConfig());
        $this->app->bind(Tagger::class, fn () => Tagger::fromConfig());
        $this->app->bind(Fingerprinter::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'log-insight');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'log-insight');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([IngestCommand::class, PruneCommand::class, RetagCommand::class, InstallCommand::class, PasswordCommand::class]);

            $this->publishes([__DIR__.'/../config/log-insight.php' => config_path('log-insight.php')], 'log-insight-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/log-insight')], 'log-insight-views');
            $this->publishes([__DIR__.'/../resources/lang' => lang_path('vendor/log-insight')], 'log-insight-lang');

            $this->app->booted(function () {
                if (config('log-insight.enabled') && config('log-insight.ingest.schedule')) {
                    $this->app->make(Schedule::class)->command('log-insight:ingest')->everyMinute()->withoutOverlapping();
                    $this->app->make(Schedule::class)->command('log-insight:prune')->daily();
                }
            });
        }

        if (! config('log-insight.enabled')) {
            return;
        }

        RateLimiter::for('log-insight', fn (Request $request) => Limit::perMinute((int) config('log-insight.rate_limit', 120))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        $this->registerComponents();
        $this->registerRoutes();
    }

    private function registerComponents(): void
    {
        // Livewire's /livewire/update endpoint bypasses our route group, so re-apply these there.
        // Ability checks (which take a parameter) are enforced inside the components themselves.
        Livewire::addPersistentMiddleware([IpAllowlist::class, SetLocale::class]);

        Livewire::component('log-insight.dashboard', Dashboard::class);
        Livewire::component('log-insight.log-browser', LogBrowser::class);
        Livewire::component('log-insight.issue-list', IssueList::class);
        Livewire::component('log-insight.tag-manager', TagManager::class);
        Livewire::component('log-insight.audit-viewer', AuditViewer::class);
    }

    private function registerRoutes(): void
    {
        $base = (array) config('log-insight.middleware', ['web', 'auth']);

        // With the built-in login the host app's auth must not intercept: it would redirect to a login that may not exist.
        if (EnvAuth::enabled()) {
            $base = array_values(array_filter($base, fn ($m) => ! (is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:')))));
        }

        $common = array_merge($base, [
            SecurityHeaders::class,
            IpAllowlist::class,
            'throttle:log-insight',
            SetLocale::class,
        ]);

        Route::group([
            'prefix' => config('log-insight.route_prefix'),
            'as' => 'log-insight.',
            'middleware' => $common,
        ], function () {
            // Login/logout are reachable without being signed in...
            $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');

            // ...everything else needs the "view" ability (which, in env mode, means being signed in).
            Route::middleware(AuthorizeAccess::class.':view')->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        });
    }
}

<?php

return [
    /*
    | Master switch. When false no routes are registered and nothing is ingested.
    */
    'enabled' => env('LOG_INSIGHT_ENABLED', true),

    /*
    | URL prefix and middleware for the viewer. Use a non-obvious prefix in production.
    | The package always appends its own security middleware (IP allowlist, authorization,
    | security headers, throttle) after these.
    */
    'route_prefix' => env('LOG_INSIGHT_PATH', 'log-insight'),
    'middleware' => ['web', 'auth'],

    /*
    | Built-in login (no app users or roles needed).
    |
    | Set LOG_INSIGHT_USERNAME and the viewer gets its own login page, with credentials that live only in .env:
    |   LOG_INSIGHT_USERNAME=admin
    |   LOG_INSIGHT_PASSWORD_HASH='$2y$12$...'    <- generate with: php artisan log-insight:password
    |   LOG_INSIGHT_PASSWORD=...                  <- alternative, plain (min. 12 chars); the hash is safer
    | In this mode the host app's "auth" middleware is not used, and the one logged-in user has full access.
    | Without LOG_INSIGHT_USERNAME the viewer uses the host app's auth + the access rules below.
    */
    'auth' => [
        'mode' => env('LOG_INSIGHT_AUTH', env('LOG_INSIGHT_USERNAME') ? 'env' : 'app'), // env | app
        'credentials' => [
            'username' => env('LOG_INSIGHT_USERNAME'),
            'password' => env('LOG_INSIGHT_PASSWORD'),
            'password_hash' => env('LOG_INSIGHT_PASSWORD_HASH'),
        ],
        'max_attempts' => 5,      // failed logins per username+IP per 5 minutes (the IP alone gets 4x)
        'idle_minutes' => 60,     // sign out after this much inactivity
        'max_hours' => 8,         // and never stay signed in longer than this
    ],

    /*
    | Access control (used when the host app's auth is in charge, i.e. auth.mode = app).
    |
    | Default-deny outside the "local" environment. Grant access by either:
    |   - LOG_INSIGHT_ALLOWED_EMAILS="a@x.com,b@x.com" (full access), or
    |   - LogInsight::authUsing(fn ($user, string $ability) => ...) in a service provider.
    |
    | Abilities: view, view-sensitive, export, manage, audit
    */
    'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_INSIGHT_ALLOWED_EMAILS', ''))))),

    /* IPs / CIDR ranges allowed to reach the viewer. Empty = no IP restriction. */
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_INSIGHT_ALLOWED_IPS', ''))))),

    /* Requests per minute per user/IP for all viewer routes. */
    'rate_limit' => env('LOG_INSIGHT_RATE_LIMIT', 120),

    /* Disables every mutating action (tag edits, issue status changes). */
    'read_only' => env('LOG_INSIGHT_READ_ONLY', false),

    /*
    | Log sources. Only files matching these directory/pattern pairs are ever read; paths are
    | resolved with realpath() and must stay inside the directory (no traversal / symlink escape).
    */
    'sources' => [
        ['type' => 'laravel', 'path' => storage_path('logs'), 'pattern' => '*.log'],
    ],

    'ingest' => [
        'chunk_size' => 500,
        /* The newest entry in a file is held back until the file is quiet for this many seconds. */
        'settle_seconds' => 2,
        'max_message_length' => 5000,
        'max_stack_length' => 65000,
        /* Register `log-insight:ingest` in the scheduler automatically (every minute). */
        'schedule' => true,

        /*
        | Read new log lines whenever someone opens or refreshes the viewer (Livewire), so it is current
        | without waiting for the scheduler. Bounded so a big backlog can't freeze the page; the rest
        | is read on the next refresh or by the scheduler.
        */
        'on_open' => env('LOG_INSIGHT_REFRESH_ON_OPEN', true),
        'min_interval' => 10,            // seconds between automatic refreshes
        'force_min_interval' => 2,       // seconds between clicks on the Refresh button
        'max_entries_on_open' => 2000,
        'poll_seconds' => 10,            // live mode refresh rate
    ],

    'retention_days' => env('LOG_INSIGHT_RETENTION_DAYS', 90),

    /* Levels counted as "errors" in the metrics dashboard. */
    'error_levels' => ['error', 'critical', 'alert', 'emergency'],

    'redaction' => [
        'enabled' => true,
        'keys' => ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'secret',
            'authorization', 'api_key', 'apikey', 'cookie', 'x-csrf-token', 'client_secret', 'private_key'],
        'patterns' => ['bearer', 'jwt', 'credit_card'],
        'mask' => '******',
    ],

    'tags' => [
        'auto' => true,
        /* Exception class (or substring) => tag name. */
        'exception_map' => [
            'QueryException' => 'database',
            'PDOException' => 'database',
            'ConnectionException' => 'http',
            'RequestException' => 'http',
            'AuthenticationException' => 'auth',
            'AuthorizationException' => 'auth',
            'TokenMismatchException' => 'csrf',
            'ModelNotFoundException' => 'not-found',
            'NotFoundHttpException' => 'not-found',
            'ValidationException' => 'validation',
            'TimeoutException' => 'timeout',
        ],
    ],

    /*
    | Alerts. Evaluated after every ingestion run (every minute when scheduled).
    | Rule types:
    |   threshold : `count` matching entries within `minutes`      (optional `levels`, `tag`)
    |   new_issue : a never-seen-before error fingerprint appeared
    |   spike     : errors in the last hour > `factor` x the average for the same hour over the previous 7 days (and >= `min`)
    | Each rule has a `cooldown` (minutes) so the same alert can't spam a channel.
    */
    'alerts' => [
        'enabled' => env('LOG_INSIGHT_ALERTS', false),
        'channels' => [
            'mail' => ['to' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_INSIGHT_ALERT_MAIL', '')))))],
            'slack' => ['webhook_url' => env('LOG_INSIGHT_SLACK_WEBHOOK')],
            'telegram' => ['bot_token' => env('LOG_INSIGHT_TELEGRAM_TOKEN'), 'chat_id' => env('LOG_INSIGHT_TELEGRAM_CHAT_ID')],
        ],
        'rules' => [
            ['name' => 'Error burst', 'type' => 'threshold', 'levels' => ['error', 'critical', 'alert', 'emergency'], 'count' => 50, 'minutes' => 10, 'cooldown' => 30],
            ['name' => 'New error type', 'type' => 'new_issue', 'cooldown' => 0],
            ['name' => 'Error spike', 'type' => 'spike', 'factor' => 3, 'min' => 20, 'cooldown' => 60],
        ],
    ],

    /* Search limits (ReDoS / abuse protection). */
    'search' => [
        'max_length' => 200,
        'max_regex_length' => 120,
    ],

    'per_page' => 25,
];

# Log Insight

A secure log viewer for Laravel with tags, rich filtering, error grouping, a metrics dashboard and alerts.
English + Arabic (RTL). Inspired by [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer), but log entries are
copied into your database so filtering, grouping and metrics are fast SQL queries. Your log files stay the source of truth.

Requires PHP 8.2+, Laravel 11/12/13, Livewire 3.

## Install

```bash
composer require appssquare/log-insight
php artisan log-insight:install      # publishes config, migrates, first ingestion
```

Open `/log-insight`. The viewer reads new log lines **by itself whenever you open the Dashboard, Logs or Issues
page** (Livewire), so no cron is required to see current data. Each page also has a **Refresh** button and a **Live**
checkbox that re-reads the logs every few seconds while the tab is visible.

This on-open refresh is throttled (10 s between automatic refreshes, 2 s for the button), never overlaps another
ingestion, reads at most 2,000 entries per call (a big backlog is read over the next refreshes; the page says
"more to load"), and never breaks the page if a log file can't be read. Tune or disable it under `ingest.*`
(`LOG_INSIGHT_REFRESH_ON_OPEN=false`).

Optional, but recommended in production: also keep the scheduler running (`php artisan schedule:run` in cron).
It ingests every minute and prunes daily, so alerts keep firing and data stays fresh even when nobody has the viewer open.

## Access control

### Option 1: built-in login with a username and password in `.env` (simplest)

No app users, roles or `hasRole()` needed. The viewer gets its own login page:

```bash
php artisan log-insight:password      # asks for a password, prints the .env lines with a hash
```

```env
LOG_INSIGHT_USERNAME=admin
LOG_INSIGHT_PASSWORD_HASH='$2y$12$....'   # keep the single quotes: the hash contains "$"
# or, less safe, a plain password of at least 12 characters:
# LOG_INSIGHT_PASSWORD=a-long-passphrase
```

Then `php artisan config:clear`. The signed-in user has full access (view, tags, issue status, audit log).
Prefer the hash: the real password never sits in a file. Plain passwords under 12 characters are refused.

Login protections: failed attempts are throttled (5 per username+IP per 5 minutes, the IP alone gets 20), every
success / failure / lockout / logout is written to the audit log (never the attempted password), the session id is
regenerated on login, CSRF is enforced, sessions expire after 60 minutes idle (and 8 hours in total; see `auth.*`
in the config), and the login never redirects to a user-supplied URL. The host app's `auth` middleware is not used in this mode.
Use HTTPS in production so the password isn't sent in clear text, and consider `LOG_INSIGHT_ALLOWED_IPS` as a second lock.

### Option 2: the host app's own users and roles

Leave `LOG_INSIGHT_USERNAME` unset. Access is then denied outside `local` until you pick one:

```env
LOG_INSIGHT_ALLOWED_EMAILS=you@company.com,ops@company.com
LOG_INSIGHT_ALLOWED_IPS=203.0.113.0/24,10.0.0.5     # optional IP / CIDR allowlist
```

or in a service provider, for per-ability control:

```php
use LogInsight\LogInsight;

LogInsight::authUsing(fn ($user, string $ability) => match ($ability) {
    'view'                       => $user?->hasRole('developer'),
    'manage', 'audit', 'export'  => $user?->hasRole('admin'),
    default                      => false,
});
```

Abilities in use: `view`, `manage` (tags, issue status), `audit` (audit log page).
`export` and `view-sensitive` are reserved names for the planned CSV export and "reveal masked secrets" features; nothing checks them yet.
`LOG_INSIGHT_READ_ONLY=true` disables every mutating action for everyone.

Other protections: Livewire update requests re-check access (not just the page route), CSP with a per-request nonce,
`X-Frame-Options: DENY`, `no-store`, `noindex`, rate limiting (`LOG_INSIGHT_RATE_LIMIT`, per user/IP per minute),
audit log of searches / entry views / status changes / denied access, and only files inside the configured source
directories are ever read (symlinks and `..` are resolved and rejected).
Secrets (passwords, tokens, Authorization headers, JWTs, Luhn-valid card numbers) are masked **before** being stored.

## Log sources

```php
// config/log-insight.php
'sources' => [
    ['type' => 'laravel',       'path' => storage_path('logs'), 'pattern' => '*.log'],   // also covers Horizon
    ['type' => 'nginx_access',  'path' => '/var/log/nginx',     'pattern' => 'access.log'],
    ['type' => 'nginx_error',   'path' => '/var/log/nginx',     'pattern' => 'error.log'],
    ['type' => 'apache_access', 'path' => '/var/log/apache2',   'pattern' => 'access.log'],
    ['type' => 'apache_error',  'path' => '/var/log/apache2',   'pattern' => 'error.log'],
    ['type' => 'supervisor',    'path' => '/var/log/supervisor','pattern' => 'supervisord.log'],
],
```

Use a separate source per format (a `*.log` pattern in one directory is parsed with one parser).
The PHP user running the scheduler must be able to read those files.
Levels are the eight PSR-3 levels; web-server logs are mapped onto them (5xx = error, 4xx = warning).
"Exception" isn't a level: use the **has exception** filter / `has:exception`.

## Filtering

UI controls: text (optionally regex / stack traces), levels, date presets or range, tags (any / all / exclude), channel,
environment, file, exception class, HTTP status & method, IP, user id, request id, saved filters, live refresh.
Everything is in the URL, so a link reproduces the view. The search box also understands:

```
level:error,critical  tag:payment  -tag:known-bug  channel:daily  env:production
exception:QueryException  has:exception  user:42  request:abc  ip:10.0.0.1
http:5xx  method:POST  after:2026-10-01  before:"2026-10-15 12:00"  status:open
"exact phrase"  /regex/
```

## Tags

* Automatic: level, environment, exception type (`QueryException` -> `database`, ...), `has-user`, `queue`.
* From code: `Log::error('Payment failed', ['tags' => ['payment', 'stripe']]);`
* Rules in the UI (**Tags** page): *message matches regex `stripe|paypal` -> `payment`*.
  Apply new rules to old entries with `php artisan log-insight:retag`.
* Manual: open an entry and add a tag.

## Metrics

Dashboard: errors today / this week / this month (each vs the previous period, cut at the same point in time),
critical today, new issues today, open issues, regressions (resolved issues that came back), errors over time by level,
top tags, top / new issues. "Errors" = levels in `error_levels` (default error, critical, alert, emergency).

## Alerts

```env
LOG_INSIGHT_ALERTS=true
LOG_INSIGHT_ALERT_MAIL=ops@company.com
LOG_INSIGHT_SLACK_WEBHOOK=https://hooks.slack.com/services/...
LOG_INSIGHT_TELEGRAM_TOKEN=123:abc
LOG_INSIGHT_TELEGRAM_CHAT_ID=-100123
```

Rules (config): `threshold` (N errors in M minutes), `new_issue` (never-seen error type), `spike`
(last hour > factor x the same hour's 7-day average). Each has a cooldown.

## Commands

| Command | Purpose |
|---|---|
| `log-insight:ingest` | Read new log lines (scheduled every minute; locked against overlap) |
| `log-insight:prune [--days=]` | Delete data past `retention_days` (scheduled daily) |
| `log-insight:retag` | Re-apply tag rules to stored entries |
| `log-insight:password` | Generate the `.env` lines (with a password hash) for the built-in login |
| `log-insight:install` | Publish config, migrate, first ingest |

## Notes and limits

* The newest entry in a file is held back until the file is quiet for `settle_seconds`, so half-written entries are never stored.
* Rotated / truncated files are detected (file shrank or inode changed); their old entries are kept.
* Regex search uses the database's `REGEXP` (MySQL/MariaDB, SQLite via a registered function) or `~*` (PostgreSQL).
  Patterns over `search.max_regex_length` characters or invalid ones match nothing.
* Search is `LIKE`-based; add a FULLTEXT index on `li_entries.message` for very large tables.
* The CSP allows `'unsafe-eval'` because Livewire's bundled Alpine needs it; no remote origins are allowed.

## Tests

```bash
composer install && vendor/bin/phpunit
```

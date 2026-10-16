# Changelog

## 1.0.0

- Log ingestion into the database: Laravel/Horizon, Nginx, Apache and Supervisor logs; incremental by byte offset, rotation-aware, secrets masked before storage.
- Reads new log lines when a page is opened, on a Refresh button, and in live mode (Livewire); scheduler optional.
- Filtering: text/regex, level, date presets and ranges, tags (any/all/exclude), channel, environment, exception, HTTP status, IP, user, request id, search syntax, saved filters.
- Tags: automatic, from `context.tags`, rules, manual.
- Issues: grouping by fingerprint, open/resolved/ignored, regression detection.
- Metrics dashboard: errors today / this week / this month vs the previous period, charts, top tags and issues.
- Alerts: threshold, new error type, spike; mail, Slack, Telegram.
- Security: built-in `.env` login or host-app auth, default-deny, IP allowlist, CSP, rate limiting, audit log, read-only mode.
- English and Arabic (RTL).

<?php

namespace LogInsight\Alerts;

use Illuminate\Support\Facades\Cache;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogIssue;

class AlertEvaluator
{
    public function __construct(private readonly AlertNotifier $notifier) {}

    /** @return int number of alerts sent */
    public function evaluate(): int
    {
        $sent = 0;

        foreach (config('log-insight.alerts.rules', []) as $i => $rule) {
            $message = match ($rule['type'] ?? '') {
                'threshold' => $this->threshold($rule),
                'new_issue' => $this->newIssues($rule),
                'spike' => $this->spike($rule),
                default => null,
            };

            if ($message === null) {
                continue;
            }

            $key = 'log-insight:alert:'.$i.':'.md5($rule['name'] ?? (string) $i);
            if (($rule['cooldown'] ?? 0) > 0 && Cache::has($key)) {
                continue;
            }

            $this->notifier->send('['.($rule['name'] ?? 'Log Insight').'] ', $message);
            $sent++;

            if (($rule['cooldown'] ?? 0) > 0) {
                Cache::put($key, true, now()->addMinutes((int) $rule['cooldown']));
            }
        }

        return $sent;
    }

    private function threshold(array $rule): ?string
    {
        $minutes = (int) ($rule['minutes'] ?? 10);
        $limit = (int) ($rule['count'] ?? 50);
        $levels = $rule['levels'] ?? config('log-insight.error_levels');

        $query = LogEntry::query()->whereIn('level', $levels)->where('logged_at', '>=', now()->subMinutes($minutes));
        if (! empty($rule['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('li_tags.name', $rule['tag']));
        }
        $count = $query->count();

        return $count >= $limit ? "$count matching log entries in the last $minutes minutes (threshold $limit)." : null;
    }

    private function newIssues(array $rule): ?string
    {
        $cacheKey = 'log-insight:alerts:last-issue-id';
        $max = (int) LogIssue::query()->max('id');
        $last = Cache::get($cacheKey);

        // First run only records a baseline, otherwise every historical issue would alert at once.
        if ($last === null) {
            Cache::forever($cacheKey, $max);

            return null;
        }

        $issues = LogIssue::query()->where('id', '>', (int) $last)->orderBy('id')->limit(5)->get();
        $total = LogIssue::query()->where('id', '>', (int) $last)->count();
        Cache::forever($cacheKey, $max);

        if ($issues->isEmpty()) {
            return null;
        }

        $lines = $issues->map(fn ($i) => '- '.($i->exception_class ? $i->exception_class.': ' : '').mb_substr($i->title, 0, 160))->implode("\n");

        return "$total new error type(s):\n$lines";
    }

    private function spike(array $rule): ?string
    {
        $levels = config('log-insight.error_levels');
        $now = now();
        $current = LogEntry::query()->whereIn('level', $levels)->where('logged_at', '>=', $now->copy()->subHour())->count();

        $history = [];
        for ($d = 1; $d <= 7; $d++) {
            $history[] = LogEntry::query()->whereIn('level', $levels)
                ->whereBetween('logged_at', [$now->copy()->subDays($d)->subHour(), $now->copy()->subDays($d)])->count();
        }
        $avg = array_sum($history) / 7;

        if ($current >= (int) ($rule['min'] ?? 20) && $current > $avg * (float) ($rule['factor'] ?? 3)) {
            return sprintf('%d errors in the last hour vs. an average of %.1f for the same hour over the past 7 days.', $current, $avg);
        }

        return null;
    }
}

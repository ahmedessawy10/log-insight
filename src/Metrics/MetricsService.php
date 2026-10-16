<?php

namespace LogInsight\Metrics;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogIssue;
use LogInsight\Models\Tag;

class MetricsService
{
    /** @return string[] */
    public function errorLevels(): array
    {
        return config('log-insight.error_levels', ['error', 'critical', 'alert', 'emergency']);
    }

    public function errorCount(Carbon $from, Carbon $to): int
    {
        return $this->errors()->whereBetween('logged_at', [$from, $to])->count();
    }

    /**
     * Headline numbers: today / this week / this month, each compared with the previous equivalent period.
     * The previous period is cut at the same point in time so "today so far" is compared with
     * "yesterday up to the same hour" rather than with a full day.
     */
    public function kpis(?Carbon $now = null): array
    {
        $now ??= now();

        $periods = [
            'today' => [$now->copy()->startOfDay(), $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()],
        ];

        $out = [];
        foreach ($periods as $key => [$start, $prevStart, $prevEnd]) {
            $current = $this->errorCount($start, $now);
            $previous = $this->errorCount($prevStart, $prevEnd);
            $out[$key] = [
                'count' => $current,
                'previous' => $previous,
                'change_percent' => $this->change($current, $previous),
            ];
        }

        $todayStart = $now->copy()->startOfDay();

        $out['critical_today'] = LogEntry::query()
            ->whereIn('level', ['critical', 'alert', 'emergency'])
            ->whereBetween('logged_at', [$todayStart, $now])->count();
        $out['new_issues_today'] = LogIssue::query()->where('first_seen_at', '>=', $todayStart)->count();
        $out['open_issues'] = LogIssue::query()->where('status', 'open')->count();
        $out['regressions'] = LogIssue::query()->whereNotNull('regressed_at')->where('status', 'open')->count();

        return $out;
    }

    /** null when there is nothing to compare against. */
    public function change(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Error counts per bucket, split by level, for a chart. Missing buckets are filled with zeros.
     *
     * @return array{labels:string[], series:array<string,int[]>, totals:int[]}
     */
    public function timeline(Carbon $from, Carbon $to, string $bucket = 'day'): array
    {
        $expr = $this->bucketExpression($bucket);
        $rows = $this->errors()
            ->whereBetween('logged_at', [$from, $to])
            ->selectRaw("$expr as bucket, level, count(*) as total")
            ->groupBy('bucket', 'level')
            ->get();

        $labels = [];
        $cursor = $from->copy();
        $format = $bucket === 'hour' ? 'Y-m-d H' : 'Y-m-d';
        while ($cursor->lte($to)) {
            $labels[] = $cursor->format($format);
            $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        $series = [];
        foreach ($this->errorLevels() as $level) {
            $series[$level] = array_fill(0, count($labels), 0);
        }
        $index = array_flip($labels);

        foreach ($rows as $row) {
            if (isset($index[$row->bucket], $series[$row->level])) {
                $series[$row->level][$index[$row->bucket]] = (int) $row->total;
            }
        }

        $totals = array_fill(0, count($labels), 0);
        foreach ($series as $values) {
            foreach ($values as $i => $v) {
                $totals[$i] += $v;
            }
        }

        return ['labels' => $labels, 'series' => $series, 'totals' => $totals];
    }

    /** @return Collection<string,int> level => count (all levels) */
    public function byLevel(Carbon $from, Carbon $to): Collection
    {
        return LogEntry::query()
            ->whereBetween('logged_at', [$from, $to])
            ->selectRaw('level, count(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level')
            ->map(fn ($v) => (int) $v);
    }

    /** @return Collection<int, object{name:string,color:string,total:int}> */
    public function topTags(Carbon $from, Carbon $to, int $limit = 10, bool $errorsOnly = true): Collection
    {
        return Tag::query()
            ->join('li_entry_tag', 'li_entry_tag.tag_id', '=', 'li_tags.id')
            ->join('li_entries', 'li_entries.id', '=', 'li_entry_tag.entry_id')
            ->whereBetween('li_entries.logged_at', [$from, $to])
            ->when($errorsOnly, fn ($q) => $q->whereIn('li_entries.level', $this->errorLevels()))
            ->selectRaw('li_tags.name, li_tags.color, count(*) as total')
            ->groupBy('li_tags.id', 'li_tags.name', 'li_tags.color')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /** Most frequent issues within the period (counts occurrences inside the period, not lifetime). */
    public function topIssues(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        $counts = LogEntry::query()
            ->whereNotNull('issue_id')
            ->whereBetween('logged_at', [$from, $to])
            ->selectRaw('issue_id, count(*) as period_count')
            ->groupBy('issue_id')
            ->orderByDesc('period_count')
            ->limit($limit)
            ->pluck('period_count', 'issue_id');

        if ($counts->isEmpty()) {
            return collect();
        }

        return LogIssue::query()->whereIn('id', $counts->keys())->get()
            ->each(fn ($i) => $i->period_count = (int) $counts[$i->id])
            ->sortByDesc('period_count')
            ->values();
    }

    /** Issues whose very first occurrence is inside the period. */
    public function newIssues(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return LogIssue::query()
            ->whereBetween('first_seen_at', [$from, $to])
            ->orderByDesc('first_seen_at')
            ->limit($limit)
            ->get();
    }

    /** Issues that were resolved and then came back. */
    public function regressions(int $limit = 10): Collection
    {
        return LogIssue::query()
            ->whereNotNull('regressed_at')
            ->where('status', 'open')
            ->orderByDesc('regressed_at')
            ->limit($limit)
            ->get();
    }

    private function errors(): Builder
    {
        return LogEntry::query()->whereIn('level', $this->errorLevels());
    }

    private function bucketExpression(string $bucket): string
    {
        $hour = $bucket === 'hour';

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $hour ? "DATE_FORMAT(logged_at, '%Y-%m-%d %H')" : "DATE_FORMAT(logged_at, '%Y-%m-%d')",
            'pgsql' => $hour ? "to_char(logged_at, 'YYYY-MM-DD HH24')" : "to_char(logged_at, 'YYYY-MM-DD')",
            default => $hour ? "strftime('%Y-%m-%d %H', logged_at)" : "strftime('%Y-%m-%d', logged_at)",
        };
    }
}

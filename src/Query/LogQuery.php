<?php

namespace LogInsight\Query;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogInsight\Models\LogEntry;

/**
 * Single source of truth for every way of filtering entries. The UI, the query-language
 * parser and saved filters all produce one of these; apply() turns it into SQL.
 */
class LogQuery
{
    public const PRESETS = [
        '15m' => 'Last 15 minutes',
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'week' => 'This week',
        'month' => 'This month',
        'last_month' => 'Last month',
    ];

    public string $search = '';
    public bool $regex = false;
    public bool $inStack = false;
    /** @var string[] */
    public array $levels = [];
    /** @var string[] match entries having ANY of these tags */
    public array $tagsAny = [];
    /** @var string[] match entries having ALL of these tags */
    public array $tagsAll = [];
    /** @var string[] exclude entries having any of these tags */
    public array $tagsNone = [];
    public ?string $preset = null;
    public ?string $from = null;
    public ?string $to = null;
    /** @var string[] */
    public array $channels = [];
    /** @var string[] */
    public array $environments = [];
    public ?string $exception = null;
    /** Only entries that carry an exception (stack trace) */
    public bool $hasException = false;
    /** "500", "5xx" or "4xx,5xx" */
    public ?string $httpStatus = null;
    public ?string $httpMethod = null;
    public ?string $ip = null;
    public ?string $userId = null;
    public ?string $requestId = null;
    public ?int $fileId = null;
    public ?int $issueId = null;
    public ?string $issueStatus = null;

    public static function fromArray(array $data): self
    {
        $q = new self;
        foreach ($data as $key => $value) {
            if (property_exists($q, $key)) {
                $q->{$key} = $value;
            }
        }

        return $q;
    }

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
    }

    /** @return array{0:?Carbon, 1:?Carbon} */
    public function range(): array
    {
        if ($this->preset && isset(self::PRESETS[$this->preset])) {
            return self::resolvePreset($this->preset);
        }

        return [
            $this->from ? $this->parseDate($this->from, false) : null,
            $this->to ? $this->parseDate($this->to, true) : null,
        ];
    }

    /** @return array{0:Carbon, 1:Carbon} */
    public static function resolvePreset(string $preset): array
    {
        $now = now();

        return match ($preset) {
            '15m' => [$now->copy()->subMinutes(15), $now],
            '1h' => [$now->copy()->subHour(), $now],
            '24h' => [$now->copy()->subDay(), $now],
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public function apply(Builder $query): Builder
    {
        [$from, $to] = $this->range();
        if ($from) {
            $query->where('logged_at', '>=', $from);
        }
        if ($to) {
            $query->where('logged_at', '<=', $to);
        }

        if ($this->levels) {
            $query->whereIn('level', $this->levels);
        }
        if ($this->channels) {
            $query->whereIn('channel', $this->channels);
        }
        if ($this->environments) {
            $query->whereIn('environment', $this->environments);
        }
        if ($this->exception) {
            $query->whereRaw("exception_class LIKE ? ESCAPE '!'", ['%'.self::escapeLike($this->exception).'%']);
        }
        if ($this->hasException) {
            $query->whereNotNull('exception_class');
        }
        if ($this->httpMethod) {
            $query->where('http_method', strtoupper($this->httpMethod));
        }
        if ($this->ip) {
            $query->where('ip', $this->ip);
        }
        if ($this->httpStatus) {
            $this->applyHttpStatus($query);
        }
        if ($this->userId !== null && $this->userId !== '') {
            $query->where('user_id', $this->userId);
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $query->where('request_id', $this->requestId);
        }
        if ($this->fileId) {
            $query->where('log_file_id', $this->fileId);
        }
        if ($this->issueId) {
            $query->where('issue_id', $this->issueId);
        }
        if ($this->issueStatus) {
            $query->whereHas('issue', fn ($q) => $q->where('status', $this->issueStatus));
        }

        if ($this->tagsAny) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('li_tags.name', $this->tagsAny));
        }
        foreach ($this->tagsAll as $tag) {
            $query->whereHas('tags', fn ($q) => $q->where('li_tags.name', $tag));
        }
        if ($this->tagsNone) {
            $query->whereDoesntHave('tags', fn ($q) => $q->whereIn('li_tags.name', $this->tagsNone));
        }

        $this->applySearch($query);

        return $query;
    }

    private function applyHttpStatus(Builder $query): void
    {
        $query->where(function (Builder $q) {
            foreach (explode(',', (string) $this->httpStatus) as $part) {
                $part = strtolower(trim($part));
                if (preg_match('/^([1-5])xx$/', $part, $m)) {
                    $q->orWhereBetween('http_status', [$m[1] * 100, $m[1] * 100 + 99]);
                } elseif (preg_match('/^\d{3}$/', $part)) {
                    $q->orWhere('http_status', (int) $part);
                }
            }
        });
    }

    private function applySearch(Builder $query): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }
        $term = mb_substr($term, 0, (int) config('log-insight.search.max_length', 200));

        if ($this->regex) {
            if (! $this->regexIsValid($term)) {
                // An invalid / too long regex matches nothing rather than erroring or running unbounded.
                $query->whereRaw('1 = 0');

                return;
            }
            $this->ensureRegexSupport($query);
            $columns = $this->inStack ? ['message', 'stack_trace'] : ['message'];
            $query->where(function (Builder $q) use ($columns, $term) {
                foreach ($columns as $col) {
                    $q->orWhereRaw($this->regexSql($col), [$term]);
                }
            });

            return;
        }

        $like = '%'.self::escapeLike($term).'%';
        $query->where(function (Builder $q) use ($like) {
            $q->whereRaw("message LIKE ? ESCAPE '!'", [$like]);
            if ($this->inStack) {
                $q->orWhereRaw("stack_trace LIKE ? ESCAPE '!'", [$like]);
            }
        });
    }

    private function regexIsValid(string $pattern): bool
    {
        if (strlen($pattern) > (int) config('log-insight.search.max_regex_length', 120)) {
            return false;
        }

        return @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', '') !== false;
    }

    private function regexSql(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "$column ~* ?",
            default => "$column REGEXP ?", // mysql, mariadb, sqlite (function registered below)
        };
    }

    private function ensureRegexSupport(Builder $query): void
    {
        $connection = $query->getModel()->getConnection();
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $connection->getPdo()->sqliteCreateFunction('regexp', function ($pattern, $value) {
            if ($value === null) {
                return 0;
            }

            return @preg_match('~'.str_replace('~', '\~', (string) $pattern).'~iu', mb_substr((string) $value, 0, 20000)) === 1 ? 1 : 0;
        }, 2);
    }

    /**
     * Escape LIKE wildcards using "!" as the escape character. A backslash default isn't portable
     * (SQLite has none), so every LIKE built with this must add "ESCAPE '!'".
     */
    public static function escapeLike(string $v): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v);
    }

    private function parseDate(string $value, bool $endOfDay): ?Carbon
    {
        try {
            $c = Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        // A bare date (no time component) covers the whole day.
        if (! preg_match('/\d{1,2}:\d{2}/', $value)) {
            return $endOfDay ? $c->endOfDay() : $c->startOfDay();
        }

        return $c;
    }

    public static function newBuilder(): Builder
    {
        return LogEntry::query();
    }
}

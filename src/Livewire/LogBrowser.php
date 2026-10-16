<?php

namespace LogInsight\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\SavedFilter;
use LogInsight\Models\Tag;
use LogInsight\Query\LogQuery;
use LogInsight\Query\QueryLanguageParser;
use LogInsight\Security\AuditLogger;

#[Layout('log-insight::layout')]
class LogBrowser extends Component
{
    use GuardsAccess;
    use RefreshesLogs;
    use WithPagination;

    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** Search box: free text plus key:value syntax (level:error tag:payment ...) */
    #[Url(as: 'q')]
    public string $q = '';
    #[Url]
    public bool $regex = false;
    #[Url]
    public bool $inStack = false;
    #[Url]
    public array $levels = [];
    #[Url]
    public array $tagsAny = [];
    #[Url]
    public array $tagsAll = [];
    #[Url]
    public array $tagsNone = [];
    #[Url]
    public string $preset = '';
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url]
    public string $channel = '';
    #[Url]
    public string $environment = '';
    #[Url]
    public string $exception = '';
    #[Url]
    public bool $hasException = false;
    #[Url]
    public string $httpStatus = '';
    #[Url]
    public string $httpMethod = '';
    #[Url]
    public string $ip = '';
    #[Url]
    public string $userId = '';
    #[Url]
    public string $requestId = '';
    #[Url]
    public string $fileId = '';
    #[Url(as: 'issue')]
    public ?int $issueId = null;

    public bool $showMore = false;
    public ?int $selectedId = null;
    public string $newTag = '';
    public string $filterName = '';

    public function mount(): void
    {
        $this->authorizeAbility('view');
        $this->refreshLogs();
    }

    /** Any filter change goes back to page 1; searches are audited (debounced by the UI). */
    public function updated(string $property): void
    {
        $this->resetPage();

        if (in_array($property, ['q', 'regex', 'inStack'], true) && trim($this->q) !== '') {
            AuditLogger::record('search', mb_substr($this->q, 0, 200), ['regex' => $this->regex]);
        }
    }

    public function toggleLevel(string $level): void
    {
        if (! in_array($level, self::LEVELS, true)) {
            return;
        }
        $this->levels = in_array($level, $this->levels, true)
            ? array_values(array_diff($this->levels, [$level]))
            : [...$this->levels, $level];
        $this->resetPage();
    }

    /** Click-to-filter from a row: add a tag to the "any of" list. */
    public function filterTag(string $name): void
    {
        if (! in_array($name, $this->tagsAny, true)) {
            $this->tagsAny[] = $name;
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'regex', 'inStack', 'levels', 'tagsAny', 'tagsAll', 'tagsNone', 'preset', 'from', 'to',
            'channel', 'environment', 'exception', 'hasException', 'httpStatus', 'httpMethod', 'ip', 'userId',
            'requestId', 'fileId', 'issueId']);
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->authorizeAbility('view');
        $this->selectedId = $id;
        $this->newTag = '';
        AuditLogger::record('view_entry', (string) $id);
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function addTag(): void
    {
        $this->authorizeMutation();

        $name = mb_substr(strtolower(trim($this->newTag)), 0, 60);
        $entry = $this->selectedId ? LogEntry::find($this->selectedId) : null;
        if ($name === '' || ! $entry) {
            return;
        }

        $tag = Tag::firstOrCreate(['name' => $name], ['type' => 'manual', 'color' => '#7c3aed']);
        $entry->tags()->syncWithoutDetaching([$tag->id]);
        $this->newTag = '';
        AuditLogger::record('tag_entry', (string) $entry->id, ['tag' => $name]);
    }

    public function saveFilter(): void
    {
        $this->authorizeAbility('view');
        $name = trim($this->filterName);
        if ($name === '') {
            return;
        }

        SavedFilter::create([
            'user_id' => auth()->id(),
            'name' => mb_substr($name, 0, 100),
            'query' => json_encode($this->buildQuery()->toArray()),
            'is_shared' => false,
        ]);
        $this->filterName = '';
    }

    public function applySaved(int $id): void
    {
        $saved = SavedFilter::query()
            ->where('id', $id)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhere('is_shared', true))
            ->first();
        if (! $saved) {
            return;
        }

        $this->clearFilters();
        $data = json_decode($saved->query, true) ?: [];
        $data['q'] = $data['search'] ?? '';
        $data['channel'] = $data['channels'][0] ?? '';
        $data['environment'] = $data['environments'][0] ?? '';

        $allowed = ['q', 'regex', 'inStack', 'levels', 'tagsAny', 'tagsAll', 'tagsNone', 'preset', 'from', 'to',
            'channel', 'environment', 'exception', 'hasException', 'httpStatus', 'httpMethod', 'ip', 'userId',
            'requestId', 'fileId', 'issueId'];
        foreach ($allowed as $prop) {
            if (array_key_exists($prop, $data) && $data[$prop] !== null) {
                $this->{$prop} = $data[$prop];
            }
        }
    }

    public function deleteSaved(int $id): void
    {
        SavedFilter::where('id', $id)->where('user_id', auth()->id())->delete();
    }

    /** Merges the structured controls with whatever was typed in the search box. */
    public function buildQuery(): LogQuery
    {
        $typed = (new QueryLanguageParser)->parse($this->q);

        $ui = LogQuery::fromArray([
            'regex' => $this->regex,
            'inStack' => $this->inStack,
            'levels' => $this->levels,
            'tagsAny' => $this->tagsAny,
            'tagsAll' => $this->tagsAll,
            'tagsNone' => $this->tagsNone,
            'preset' => $this->preset ?: null,
            'from' => $this->from ?: null,
            'to' => $this->to ?: null,
            'channels' => $this->channel ? [$this->channel] : [],
            'environments' => $this->environment ? [$this->environment] : [],
            'exception' => $this->exception ?: null,
            'hasException' => $this->hasException,
            'httpStatus' => $this->httpStatus ?: null,
            'httpMethod' => $this->httpMethod ?: null,
            'ip' => $this->ip ?: null,
            'userId' => $this->userId ?: null,
            'requestId' => $this->requestId ?: null,
            'fileId' => ctype_digit($this->fileId) ? (int) $this->fileId : null,
            'issueId' => $this->issueId,
        ]);

        foreach (['levels', 'tagsAny', 'tagsAll', 'tagsNone', 'channels', 'environments'] as $list) {
            $ui->{$list} = array_values(array_unique([...$ui->{$list}, ...$typed->{$list}]));
        }
        foreach (['exception', 'httpStatus', 'httpMethod', 'ip', 'userId', 'requestId', 'issueStatus', 'from', 'to'] as $scalar) {
            $ui->{$scalar} = $typed->{$scalar} ?: $ui->{$scalar};
        }
        $ui->search = $typed->search;
        $ui->regex = $typed->regex || $this->regex;
        $ui->inStack = $typed->inStack || $this->inStack;
        $ui->hasException = $typed->hasException || $this->hasException;
        if ($typed->from || $typed->to) {
            $ui->preset = null; // explicit typed dates win over a preset
        }

        return $ui;
    }

    public function render()
    {
        $this->authorizeAbility('view');

        $query = $this->buildQuery();
        $entries = $query->apply(LogEntry::query())
            ->with(['tags:id,name,color', 'file:id,path,type'])
            ->orderByDesc('logged_at')->orderByDesc('id')
            ->paginate((int) config('log-insight.per_page', 25));

        $selected = $this->selectedId
            ? LogEntry::with(['tags', 'file', 'issue'])->find($this->selectedId)
            : null;

        return view('log-insight::livewire.log-browser', [
            'entries' => $entries,
            'selected' => $selected,
            'related' => $selected?->issue_id ? LogEntry::where('issue_id', $selected->issue_id)->count() : 0,
            'allTags' => Tag::query()->orderBy('name')->limit(300)->get(['id', 'name', 'color']),
            'channels' => LogEntry::query()->whereNotNull('channel')->distinct()->orderBy('channel')->pluck('channel'),
            'environments' => LogEntry::query()->whereNotNull('environment')->distinct()->orderBy('environment')->pluck('environment'),
            'files' => LogFile::query()->orderBy('path')->get(['id', 'path']),
            'saved' => SavedFilter::query()
                ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhere('is_shared', true))
                ->orderBy('name')->get(),
            'presets' => LogQuery::PRESETS,
            'levelList' => self::LEVELS,
            'regexInvalid' => $query->regex && trim($query->search) !== '' && @preg_match('~'.str_replace('~', '\~', $query->search).'~iu', '') === false,
            'queryHasFilters' => $query->toArray() !== [],
            'pollSeconds' => $this->pollSeconds(),
        ]);
    }
}

<?php

namespace LogInsight\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use LogInsight\Models\LogIssue;
use LogInsight\Query\LogQuery;
use LogInsight\Security\AuditLogger;

#[Layout('log-insight::layout')]
class IssueList extends Component
{
    use GuardsAccess;
    use RefreshesLogs;
    use WithPagination;

    #[Url]
    public string $status = 'open';
    #[Url]
    public string $sort = 'recent';
    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeAbility('view');
        $this->refreshLogs();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorizeMutation();
        if (! in_array($status, ['open', 'resolved', 'ignored'], true)) {
            return;
        }

        $issue = LogIssue::findOrFail($id);
        $issue->status = $status;
        $issue->resolved_at = $status === 'resolved' ? now() : null;
        $issue->resolved_by = $status === 'resolved' ? (auth()->user()?->email ?? (string) auth()->id()) : null;
        if ($status === 'open') {
            $issue->regressed_at = null;
        }
        $issue->save();

        AuditLogger::record('issue_'.$status, (string) $id);
    }

    public function render()
    {
        $this->authorizeAbility('view');

        $issues = LogIssue::query()
            ->when(in_array($this->status, ['open', 'resolved', 'ignored'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.LogQuery::escapeLike(mb_substr(trim($this->search), 0, 200)).'%';
                $q->where(fn ($w) => $w->whereRaw("title LIKE ? ESCAPE '!'", [$like])->orWhereRaw("exception_class LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($this->sort === 'count', fn ($q) => $q->orderByDesc('occurrences'))
            ->when($this->sort === 'new', fn ($q) => $q->orderByDesc('first_seen_at'))
            ->when(! in_array($this->sort, ['count', 'new'], true), fn ($q) => $q->orderByDesc('last_seen_at'))
            ->paginate((int) config('log-insight.per_page', 25));

        return view('log-insight::livewire.issue-list', ['issues' => $issues, 'pollSeconds' => $this->pollSeconds()]);
    }
}

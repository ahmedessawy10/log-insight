<?php

namespace LogInsight\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use LogInsight\Models\AuditLog;

#[Layout('log-insight::layout')]
class AuditViewer extends Component
{
    use GuardsAccess;
    use WithPagination;

    #[Url]
    public string $action = '';

    public function mount(): void
    {
        $this->authorizeAbility('audit');
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->authorizeAbility('audit');

        return view('log-insight::livewire.audit-viewer', [
            'rows' => AuditLog::query()
                ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
                ->orderByDesc('id')
                ->paginate(30),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}

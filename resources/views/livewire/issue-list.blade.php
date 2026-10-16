@php $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep); @endphp

<div @if ($this->live) wire:poll.{{ $pollSeconds }}s.visible="poll" @endif>
    <div class="row spread">
        <h1>{{ $t('issues.title') }}</h1>
        @include('log-insight::partials.refresh-bar', ['updatedAt' => $this->lastRefreshed, 'newCount' => $this->newEntries, 'more' => $this->refreshMore, 'pollSeconds' => $pollSeconds])
    </div>

    <div class="card row" style="margin-bottom: 12px">
        <input type="search" class="grow" wire:model.live.debounce.400ms="search" placeholder="{{ $t('filters.search') }}" maxlength="200" aria-label="{{ $t('filters.search') }}">
        <select wire:model.live="status" aria-label="{{ $t('issues.status') }}">
            <option value="all">{{ $t('issues.all_statuses') }}</option>
            @foreach (['open', 'resolved', 'ignored'] as $s)<option value="{{ $s }}">{{ $t('issues.'.$s) }}</option>@endforeach
        </select>
        <select wire:model.live="sort" aria-label="{{ $t('issues.sort') }}">
            <option value="recent">{{ $t('issues.sort_recent') }}</option>
            <option value="count">{{ $t('issues.sort_count') }}</option>
            <option value="new">{{ $t('issues.sort_new') }}</option>
        </select>
    </div>

    <section class="card" style="padding: 0">
        @if ($issues->isEmpty())
            <div class="empty">{{ $t('issues.empty') }}</div>
        @else
            <div style="overflow-x: auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ $t('table.issue') }}</th>
                        <th>{{ $t('dash.occurrences') }}</th>
                        <th>{{ $t('dash.first_seen') }}</th>
                        <th>{{ $t('dash.last_seen') }}</th>
                        <th>{{ $t('issues.status') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($issues as $issue)
                        <tr wire:key="issue-{{ $issue->id }}">
                            <td>
                                <a href="{{ route('log-insight.logs', ['issue' => $issue->id]) }}"><div class="msg">{{ $issue->title }}</div></a>
                                <div class="muted mono" dir="ltr">{{ $issue->exception_class }}</div>
                            </td>
                            <td class="mono">{{ number_format($issue->occurrences) }}</td>
                            <td class="muted">{{ $issue->first_seen_at?->format('Y-m-d H:i') }}</td>
                            <td class="muted">{{ $issue->last_seen_at?->diffForHumans() }}</td>
                            <td>
                                {{ $t('issues.'.$issue->status) }}
                                @if ($issue->regressed_at && $issue->status === 'open')
                                    <span class="badge" style="background: var(--warn)">{{ $t('issues.regressed') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($this->canManage())
                                    <div class="row">
                                        @if ($issue->status !== 'resolved')
                                            <button type="button" wire:click="setStatus({{ $issue->id }}, 'resolved')">{{ $t('issues.resolve') }}</button>
                                        @endif
                                        @if ($issue->status !== 'ignored')
                                            <button type="button" wire:click="setStatus({{ $issue->id }}, 'ignored')">{{ $t('issues.ignore') }}</button>
                                        @endif
                                        @if ($issue->status !== 'open')
                                            <button type="button" wire:click="setStatus({{ $issue->id }}, 'open')">{{ $t('issues.reopen') }}</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($issues->hasPages())
                <div class="pager" style="padding-bottom: 14px">
                    <button type="button" wire:click="previousPage" @disabled($issues->onFirstPage())>{{ $t('common.previous') }}</button>
                    <span class="muted">{{ $issues->currentPage() }} / {{ $issues->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $issues->hasMorePages())>{{ $t('common.next') }}</button>
                </div>
            @endif
        @endif
    </section>
</div>

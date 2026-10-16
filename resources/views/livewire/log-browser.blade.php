@php $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep); @endphp

<div @if ($this->live) wire:poll.{{ $pollSeconds }}s.visible="poll" @endif>
    <div class="row spread">
        <h1>{{ $t('nav.logs') }}</h1>
        @include('log-insight::partials.refresh-bar', ['updatedAt' => $this->lastRefreshed, 'newCount' => $this->newEntries, 'more' => $this->refreshMore, 'pollSeconds' => $pollSeconds])
    </div>

    <section class="card filters" aria-label="{{ $t('filters.title') }}">
        <div class="row">
            <input type="search" class="grow" wire:model.live.debounce.500ms="q" maxlength="{{ config('log-insight.search.max_length', 200) }}"
                   placeholder="{{ $t('filters.search_placeholder') }}" aria-label="{{ $t('filters.search') }}">
            <label class="row"><input type="checkbox" wire:model.live="regex"> {{ $t('filters.regex') }}</label>
            <label class="row"><input type="checkbox" wire:model.live="inStack"> {{ $t('filters.in_stack') }}</label>
        </div>
        <div class="muted" style="font-size: 12px" dir="ltr">{{ $t('filters.syntax_help') }}</div>

        @if ($regexInvalid)
            <div class="notice warn-box">{{ $t('filters.invalid_regex') }}</div>
        @endif

        <div class="row" role="group" aria-label="{{ $t('filters.levels') }}">
            @foreach ($levelList as $level)
                <button type="button" wire:click="toggleLevel('{{ $level }}')" @class(['chip', 'on' => in_array($level, $levels, true)]) aria-pressed="{{ in_array($level, $levels, true) ? 'true' : 'false' }}">
                    <span class="dot" style="background: var(--lv-{{ $level }})"></span>{{ $t('levels.'.$level) }}
                </button>
            @endforeach
            <span class="muted">|</span>
            <label class="row"><input type="checkbox" wire:model.live="hasException"> {{ $t('filters.has_exception') }}</label>
        </div>

        <div class="filter-grid">
            <div>
                <label for="preset">{{ $t('filters.date') }}</label>
                <select id="preset" wire:model.live="preset" style="width: 100%">
                    <option value="">{{ $t('filters.any_time') }}</option>
                    @foreach ($presets as $key => $label)
                        <option value="{{ $key }}">{{ $t('presets.'.$key) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="from">{{ $t('filters.from') }}</label>
                <input id="from" type="datetime-local" wire:model.live="from" style="width: 100%">
            </div>
            <div>
                <label for="to">{{ $t('filters.to') }}</label>
                <input id="to" type="datetime-local" wire:model.live="to" style="width: 100%">
            </div>
            <div>
                <label for="tagsAny">{{ $t('filters.tags_any') }}</label>
                <select id="tagsAny" multiple wire:model.live="tagsAny" style="width: 100%" size="3">
                    @foreach ($allTags as $tag)
                        <option value="{{ $tag->name }}">{{ $tag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tagsAll">{{ $t('filters.tags_all') }}</label>
                <select id="tagsAll" multiple wire:model.live="tagsAll" style="width: 100%" size="3">
                    @foreach ($allTags as $tag)
                        <option value="{{ $tag->name }}">{{ $tag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tagsNone">{{ $t('filters.tags_none') }}</label>
                <select id="tagsNone" multiple wire:model.live="tagsNone" style="width: 100%" size="3">
                    @foreach ($allTags as $tag)
                        <option value="{{ $tag->name }}">{{ $tag->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <button type="button" wire:click="$toggle('showMore')">{{ $t('filters.more') }} {{ $showMore ? '▴' : '▾' }}</button>
        </div>

        @if ($showMore)
            <div class="filter-grid">
                <div>
                    <label for="channel">{{ $t('filters.channel') }}</label>
                    <select id="channel" wire:model.live="channel" style="width: 100%">
                        <option value="">{{ $t('filters.all') }}</option>
                        @foreach ($channels as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="environment">{{ $t('filters.environment') }}</label>
                    <select id="environment" wire:model.live="environment" style="width: 100%">
                        <option value="">{{ $t('filters.all') }}</option>
                        @foreach ($environments as $e)<option value="{{ $e }}">{{ $e }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="fileId">{{ $t('filters.file') }}</label>
                    <select id="fileId" wire:model.live="fileId" style="width: 100%">
                        <option value="">{{ $t('filters.all') }}</option>
                        @foreach ($files as $f)<option value="{{ $f->id }}">{{ basename($f->path) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="exception">{{ $t('filters.exception') }}</label>
                    <input id="exception" type="text" wire:model.live.debounce.500ms="exception" style="width: 100%" maxlength="120">
                </div>
                <div>
                    <label for="httpStatus">{{ $t('filters.http_status') }}</label>
                    <input id="httpStatus" type="text" wire:model.live.debounce.500ms="httpStatus" style="width: 100%" maxlength="30">
                </div>
                <div>
                    <label for="httpMethod">{{ $t('filters.method') }}</label>
                    <select id="httpMethod" wire:model.live="httpMethod" style="width: 100%">
                        <option value="">{{ $t('filters.all') }}</option>
                        @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m)<option>{{ $m }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="ip">{{ $t('filters.ip') }}</label>
                    <input id="ip" type="text" wire:model.live.debounce.500ms="ip" style="width: 100%" maxlength="64">
                </div>
                <div>
                    <label for="userId">{{ $t('filters.user_id') }}</label>
                    <input id="userId" type="text" wire:model.live.debounce.500ms="userId" style="width: 100%" maxlength="80">
                </div>
                <div>
                    <label for="requestId">{{ $t('filters.request_id') }}</label>
                    <input id="requestId" type="text" wire:model.live.debounce.500ms="requestId" style="width: 100%" maxlength="80">
                </div>
            </div>
        @endif

        <div class="row spread">
            <div class="row">
                @if ($queryHasFilters)
                    <button type="button" wire:click="clearFilters">{{ $t('filters.clear') }}</button>
                @endif
                <input type="text" wire:model="filterName" wire:keydown.enter="saveFilter" placeholder="{{ $t('filters.filter_name') }}" maxlength="100">
                <button type="button" wire:click="saveFilter">{{ $t('filters.save') }}</button>
            </div>
            @if ($saved->isNotEmpty())
                <div class="row" aria-label="{{ $t('filters.saved') }}">
                    <span class="muted">{{ $t('filters.saved') }}:</span>
                    @foreach ($saved as $s)
                        <span class="chip" wire:key="saved-{{ $s->id }}">
                            <button type="button" wire:click="applySaved({{ $s->id }})" style="border: 0; background: none; padding: 0">{{ $s->name }}</button>
                            @if ((string) $s->user_id === (string) auth()->id())
                                <button type="button" wire:click="deleteSaved({{ $s->id }})" style="border: 0; background: none; padding: 0" aria-label="{{ $t('tags.delete') }}">×</button>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="card" style="margin-top: 12px; padding: 0">
        <div class="row spread" style="padding: 12px 16px 0">
            <strong>{{ $t('table.results', ['count' => number_format($entries->total())]) }}</strong>
        </div>
        @if ($entries->isEmpty())
            <div class="empty">{{ $t('table.no_results') }}</div>
        @else
            <div style="overflow-x: auto">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 150px">{{ $t('table.time') }}</th>
                        <th style="width: 90px">{{ $t('table.level') }}</th>
                        <th>{{ $t('table.message') }}</th>
                        <th>{{ $t('table.tags') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr class="clickable" wire:key="entry-{{ $entry->id }}" wire:click="select({{ $entry->id }})" tabindex="0" wire:keydown.enter="select({{ $entry->id }})">
                            <td class="mono muted" dir="ltr">{{ $entry->logged_at->format('Y-m-d H:i:s') }}</td>
                            <td><x-log-insight::level-badge :level="$entry->level" /></td>
                            <td>
                                <div class="msg">{{ $entry->message }}</div>
                                <div class="muted mono">
                                    @if ($entry->exception_class){{ class_basename(str_replace('\\', '/', $entry->exception_class)) }} · @endif
                                    {{ $entry->channel ?? basename($entry->file?->path ?? '') }}
                                </div>
                            </td>
                            <td>
                                @foreach ($entry->tags as $tag)
                                    <span class="tag" wire:click.stop="filterTag(@js($tag->name))" style="border-inline-start: 3px solid {{ preg_match('/^#[0-9a-fA-F]{6}$/', $tag->color) ? $tag->color : '#6b7280' }}">{{ $tag->name }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())
                <div class="pager" style="padding-bottom: 14px">
                    <button type="button" wire:click="previousPage" @disabled($entries->onFirstPage())>{{ $t('common.previous') }}</button>
                    <span class="muted">{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $entries->hasMorePages())>{{ $t('common.next') }}</button>
                </div>
            @endif
        @endif
    </section>

    @if ($selected)
        <aside class="drawer" role="dialog" aria-modal="true" aria-label="{{ $t('table.details') }}" wire:key="drawer-{{ $selected->id }}">
            <div class="row spread">
                <h2 style="margin: 0"><x-log-insight::level-badge :level="$selected->level" /> {{ $t('table.details') }}</h2>
                <button type="button" wire:click="closeDetail">{{ $t('table.close') }}</button>
            </div>

            <dl class="kv">
                <dt>{{ $t('table.time') }}</dt><dd class="mono" dir="ltr">{{ $selected->logged_at->format('Y-m-d H:i:s') }}</dd>
                <dt>{{ $t('table.message') }}</dt><dd>{{ $selected->message }}</dd>
                @if ($selected->exception_class)
                    <dt>{{ $t('table.exception') }}</dt><dd class="mono" dir="ltr">{{ $selected->exception_class }}</dd>
                    <dt>{{ $t('table.location') }}</dt><dd class="mono" dir="ltr">{{ $selected->exception_file }}:{{ $selected->exception_line }}</dd>
                @endif
                @if ($selected->http_status)
                    <dt>HTTP</dt><dd class="mono" dir="ltr">{{ $selected->http_method }} {{ $selected->url }} → {{ $selected->http_status }}</dd>
                @endif
                @if ($selected->ip)<dt>IP</dt><dd class="mono" dir="ltr">{{ $selected->ip }}</dd>@endif
                @if ($selected->user_id)<dt>{{ $t('filters.user_id') }}</dt><dd>{{ $selected->user_id }}</dd>@endif
                @if ($selected->request_id)<dt>{{ $t('filters.request_id') }}</dt><dd class="mono">{{ $selected->request_id }}</dd>@endif
                <dt>{{ $t('table.source') }}</dt><dd class="mono" dir="ltr">{{ $selected->file?->path }}</dd>
                @if ($selected->issue)
                    <dt>{{ $t('table.issue') }}</dt>
                    <dd>
                        <a href="{{ route('log-insight.logs', ['issue' => $selected->issue_id]) }}">{{ $t('table.related') }}: {{ number_format($related) }}</a>
                        · {{ $t('issues.'.$selected->issue->status) }}
                    </dd>
                @endif
                <dt>{{ $t('table.tags') }}</dt>
                <dd>
                    @foreach ($selected->tags as $tag)<span class="tag">{{ $tag->name }}</span>@endforeach
                    @if ($this->canManage())
                        <div class="row" style="margin-top: 6px">
                            <input type="text" wire:model="newTag" wire:keydown.enter="addTag" placeholder="{{ $t('table.tag_name') }}" maxlength="60">
                            <button type="button" wire:click="addTag">{{ $t('table.add_tag') }}</button>
                        </div>
                    @endif
                </dd>
            </dl>

            @if ($selected->context)
                <h2>{{ $t('table.context') }}</h2>
                <pre>{{ json_encode($selected->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif

            @if ($selected->stack_trace)
                <h2>{{ $t('table.stack_trace') }}</h2>
                <pre>{{ $selected->stack_trace }}</pre>
            @endif

            <p class="muted" style="font-size: 12px">{{ $t('table.sensitive_note') }}</p>
        </aside>
    @endif
</div>

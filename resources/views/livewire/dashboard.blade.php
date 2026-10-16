@php
    $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep);
    $logsUrl = fn (array $q = []) => route('log-insight.logs', $q);
    $kpiCards = [
        ['key' => 'today', 'label' => 'kpi.errors_today', 'link' => ['preset' => 'today', 'levels' => $errorLevels]],
        ['key' => 'week', 'label' => 'kpi.errors_week', 'link' => ['preset' => 'week', 'levels' => $errorLevels]],
        ['key' => 'month', 'label' => 'kpi.errors_month', 'link' => ['preset' => 'month', 'levels' => $errorLevels]],
    ];
    $max = max(1, ...($timeline['totals'] ?: [0]));
    $levelTotal = max(1, $levels->sum());
@endphp

<div @if ($this->live) wire:poll.{{ $pollSeconds }}s.visible="poll" @endif>
    <div class="row spread">
        <h1>{{ $t('nav.dashboard') }}</h1>
        <div class="row">
            @include('log-insight::partials.refresh-bar', ['updatedAt' => $this->lastRefreshed, 'newCount' => $this->newEntries, 'more' => $this->refreshMore, 'pollSeconds' => $pollSeconds])
            <label for="period" class="muted">{{ $t('dash.period') }}</label>
            <select id="period" wire:model.live="period">
                @foreach ($periods as $key => $label)
                    <option value="{{ $key }}">{{ $t('presets.'.$key) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <section class="grid kpis" aria-label="KPIs">
        @foreach ($kpiCards as $card)
            @php $k = $kpis[$card['key']]; @endphp
            <div class="card kpi">
                <a href="{{ $logsUrl($card['link']) }}">
                    <div class="label">{{ $t($card['label']) }}</div>
                    <div class="value">{{ number_format($k['count']) }}</div>
                    <div class="delta">
                        @if ($k['change_percent'] === null)
                            {{ $t('kpi.no_baseline') }}
                        @else
                            <span class="{{ $k['change_percent'] > 0 ? 'up' : ($k['change_percent'] < 0 ? 'down' : '') }}">
                                {{ $k['change_percent'] > 0 ? '▲' : ($k['change_percent'] < 0 ? '▼' : '=') }}
                                {{ abs($k['change_percent']) }}%
                            </span>
                            {{ $t('kpi.vs_previous') }} ({{ number_format($k['previous']) }})
                        @endif
                    </div>
                </a>
            </div>
        @endforeach
        <div @class(['card kpi', 'alert' => $kpis['critical_today'] > 0])>
            <a href="{{ $logsUrl(['preset' => 'today', 'levels' => ['critical', 'alert', 'emergency']]) }}">
                <div class="label">{{ $t('kpi.critical_today') }}</div>
                <div class="value">{{ number_format($kpis['critical_today']) }}</div>
            </a>
        </div>
        <div class="card kpi">
            <a href="{{ route('log-insight.issues', ['status' => 'open', 'sort' => 'new']) }}">
                <div class="label">{{ $t('kpi.new_issues_today') }}</div>
                <div class="value">{{ number_format($kpis['new_issues_today']) }}</div>
            </a>
        </div>
        <div class="card kpi">
            <a href="{{ route('log-insight.issues', ['status' => 'open']) }}">
                <div class="label">{{ $t('kpi.open_issues') }}</div>
                <div class="value">{{ number_format($kpis['open_issues']) }}</div>
                @if ($kpis['regressions'] > 0)
                    <div class="delta up">{{ $t('kpi.regressions') }}: {{ $kpis['regressions'] }}</div>
                @endif
            </a>
        </div>
    </section>

    <section class="card">
        <h2>{{ $t('dash.errors_over_time') }}
            <span class="muted mono" dir="ltr">{{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }}</span>
        </h2>
        @if (array_sum($timeline['totals']) === 0)
            <div class="empty">{{ $t('dash.no_errors') }}</div>
        @else
            <div class="chart" role="img" aria-label="{{ $t('dash.errors_over_time') }}">
                @foreach ($timeline['labels'] as $i => $label)
                    <div class="col" title="{{ $label }}{{ $hourly ? ':00' : '' }} - {{ $timeline['totals'][$i] }}">
                        @foreach ($timeline['series'] as $level => $values)
                            @if (($values[$i] ?? 0) > 0)
                                <div class="seg" style="height: {{ round($values[$i] / $max * 100, 2) }}%; background: var(--lv-{{ $level }})"></div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div class="chart-axis">
                <span>{{ $timeline['labels'][0] ?? '' }}</span>
                <span>{{ end($timeline['labels']) ?: '' }}</span>
            </div>
            <div class="row" style="margin-top: 8px">
                @foreach (array_keys($timeline['series']) as $level)
                    <span class="muted"><span class="dot" style="background: var(--lv-{{ $level }})"></span> {{ $t('levels.'.$level) }}</span>
                @endforeach
            </div>
        @endif
    </section>

    <div class="grid two">
        <section class="card">
            <h2>{{ $t('dash.by_level') }}</h2>
            @forelse ($levels->sortByDesc(fn ($v) => $v) as $level => $count)
                <a class="row spread" style="color: inherit; margin-bottom: 6px" href="{{ $logsUrl(['preset' => $activePeriod, 'levels' => [$level]]) }}">
                    <span style="width: 90px">{{ $t('levels.'.$level) }}</span>
                    <span class="bar grow"><span style="width: {{ round($count / $levelTotal * 100, 1) }}%; background: var(--lv-{{ $level }})"></span></span>
                    <span class="mono" style="width: 64px; text-align: end">{{ number_format($count) }}</span>
                </a>
            @empty
                <div class="empty">{{ $t('dash.nothing') }}</div>
            @endforelse
        </section>

        <section class="card">
            <h2>{{ $t('dash.top_tags') }}</h2>
            @forelse ($tags as $tag)
                <a class="row spread" style="color: inherit; margin-bottom: 6px" href="{{ $logsUrl(['preset' => $activePeriod, 'levels' => $errorLevels, 'tagsAny' => [$tag->name]]) }}">
                    <span><span class="dot" style="background: {{ $tag->color }}"></span> {{ $tag->name }}</span>
                    <span class="mono">{{ number_format($tag->total) }}</span>
                </a>
            @empty
                <div class="empty">{{ $t('dash.nothing') }}</div>
            @endforelse
        </section>
    </div>

    <section class="card" style="margin-top: 12px">
        <h2>{{ $t('dash.top_issues') }}</h2>
        @include('log-insight::partials.issue-table', ['issues' => $topIssues, 'countField' => 'period_count'])
    </section>

    <div class="grid two">
        <section class="card">
            <h2>{{ $t('dash.new_issues') }}</h2>
            @include('log-insight::partials.issue-table', ['issues' => $newIssues, 'countField' => 'occurrences', 'compact' => true])
        </section>
        <section class="card">
            <h2>{{ $t('dash.regressions') }}</h2>
            @include('log-insight::partials.issue-table', ['issues' => $regressions, 'countField' => 'occurrences', 'compact' => true])
        </section>
    </div>
</div>

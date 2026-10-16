@php($compact = $compact ?? false)
@if ($issues->isEmpty())
    <div class="empty">{{ __('log-insight::messages.dash.nothing') }}</div>
@else
    <table>
        <thead>
        <tr>
            <th>{{ __('log-insight::messages.table.issue') }}</th>
            <th>{{ __('log-insight::messages.dash.occurrences') }}</th>
            @unless ($compact)
                <th>{{ __('log-insight::messages.dash.first_seen') }}</th>
            @endunless
            <th>{{ __('log-insight::messages.dash.last_seen') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($issues as $issue)
            <tr>
                <td>
                    <a href="{{ route('log-insight.logs', ['issue' => $issue->id]) }}">
                        <div class="msg">{{ $issue->title }}</div>
                    </a>
                    @if ($issue->exception_class)
                        <div class="muted mono">{{ class_basename(str_replace('\\', '/', $issue->exception_class)) }}</div>
                    @endif
                </td>
                <td class="mono">{{ number_format($issue->{$countField} ?? $issue->occurrences) }}</td>
                @unless ($compact)
                    <td class="muted">{{ $issue->first_seen_at?->format('Y-m-d H:i') }}</td>
                @endunless
                <td class="muted">{{ $issue->last_seen_at?->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

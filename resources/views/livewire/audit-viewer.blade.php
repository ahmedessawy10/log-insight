@php $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep); @endphp

<div>
    <div class="row spread">
        <h1>{{ $t('audit.title') }}</h1>
        <select wire:model.live="action" aria-label="{{ $t('audit.action') }}">
            <option value="">{{ $t('filters.all') }}</option>
            @foreach ($actions as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
        </select>
    </div>

    <section class="card" style="padding: 0">
        @if ($rows->isEmpty())
            <div class="empty">{{ $t('audit.empty') }}</div>
        @else
            <div style="overflow-x: auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ $t('audit.when') }}</th><th>{{ $t('audit.user') }}</th><th>{{ $t('audit.action') }}</th>
                        <th>{{ $t('audit.target') }}</th><th>{{ $t('audit.ip') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="audit-{{ $row->id }}">
                            <td class="mono muted" dir="ltr">{{ $row->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $row->user_label ?? $row->user_id ?? '-' }}</td>
                            <td><span class="tag" style="cursor: default">{{ $row->action }}</span></td>
                            <td class="mono"><div class="msg" dir="ltr">{{ $row->target }}</div></td>
                            <td class="mono" dir="ltr">{{ $row->ip }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="pager" style="padding-bottom: 14px">
                    <button type="button" wire:click="previousPage" @disabled($rows->onFirstPage())>{{ $t('common.previous') }}</button>
                    <span class="muted">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $rows->hasMorePages())>{{ $t('common.next') }}</button>
                </div>
            @endif
        @endif
    </section>
</div>

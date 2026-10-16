@php $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep); @endphp
<div class="row" style="color: var(--muted); font-size: 12px" aria-live="polite">
    @if ($updatedAt)
        <span>{{ $t('refresh.updated', ['time' => $updatedAt]) }}</span>
        @if ($newCount > 0)
            <span style="color: var(--good)">· {{ $t('refresh.new', ['count' => number_format($newCount)]) }}</span>
        @endif
        @if ($more)
            <span style="color: var(--warn)">· {{ $t('refresh.more') }}</span>
        @endif
    @endif
    <button type="button" wire:click="refreshNow" wire:loading.attr="disabled" wire:target="refreshNow">
        <span wire:loading.remove wire:target="refreshNow">{{ $t('refresh.button') }}</span>
        <span wire:loading wire:target="refreshNow">{{ $t('refresh.loading') }}</span>
    </button>
    <label class="row" title="{{ $t('refresh.live_hint', ['seconds' => $pollSeconds]) }}">
        <input type="checkbox" wire:model.live="live"> {{ $t('table.live') }}
    </label>
</div>

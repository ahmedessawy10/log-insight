@php $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep); @endphp

<div>
    <h1>{{ $t('tags.title') }}</h1>

    <section class="card">
        <h2>{{ $t('tags.rules') }}</h2>
        <p class="muted">{{ $t('tags.rules_help') }}</p>

        @if ($this->canManage())
            <form class="row" wire:submit="addRule">
                <select wire:model="field" aria-label="{{ $t('tags.field') }}">
                    @foreach (['message', 'channel', 'exception_class', 'stack_trace', 'context'] as $f)
                        <option value="{{ $f }}">{{ $t('tags.fields.'.$f) }}</option>
                    @endforeach
                </select>
                <select wire:model="operator" aria-label="{{ $t('tags.operator') }}">
                    @foreach (['contains', 'equals', 'starts_with', 'regex'] as $o)
                        <option value="{{ $o }}">{{ $t('tags.'.$o) }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="value" placeholder="{{ $t('tags.value') }}" maxlength="120" aria-label="{{ $t('tags.value') }}">
                <span>→</span>
                <input type="text" wire:model="tagName" placeholder="{{ $t('tags.tag') }}" maxlength="60" aria-label="{{ $t('tags.tag') }}">
                <button type="submit" class="primary">{{ $t('tags.add_rule') }}</button>
            </form>
            @error('value')<div class="notice warn-box">{{ $message }}</div>@enderror
            @error('tagName')<div class="notice warn-box">{{ $message }}</div>@enderror
        @endif

        @if ($rules->isEmpty())
            <div class="empty">{{ $t('tags.no_rules') }}</div>
        @else
            <table style="margin-top: 12px">
                <tbody>
                @foreach ($rules as $rule)
                    <tr wire:key="rule-{{ $rule->id }}">
                        <td>{{ $t('tags.fields.'.$rule->field) }} {{ $t('tags.'.$rule->operator) }} <code class="mono" dir="ltr">{{ $rule->value }}</code></td>
                        <td>→ <span class="tag">{{ $rule->tag->name }}</span></td>
                        <td style="text-align: end">
                            @if ($this->canManage())
                                <button type="button" class="danger" wire:click="deleteRule({{ $rule->id }})" wire:confirm="{{ $t('tags.delete') }}?">{{ $t('tags.delete') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card" style="margin-top: 12px">
        <h2>{{ $t('tags.title') }}</h2>
        <table>
            <thead><tr><th>{{ $t('tags.tag') }}</th><th>{{ $t('tags.type') }}</th><th>{{ $t('tags.entries') }}</th></tr></thead>
            <tbody>
            @foreach ($tags as $tag)
                <tr wire:key="tag-{{ $tag->id }}">
                    <td><span class="dot" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $tag->color) ? $tag->color : '#6b7280' }}"></span>
                        <a href="{{ route('log-insight.logs', ['tagsAny' => [$tag->name]]) }}">{{ $tag->name }}</a></td>
                    <td class="muted">{{ $tag->type }}</td>
                    <td class="mono">{{ number_format($tag->entries_count) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>

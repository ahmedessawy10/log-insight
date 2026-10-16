@props(['level'])
<span class="badge" style="background: var(--lv-{{ in_array($level, ['emergency','alert','critical','error','warning','notice','info','debug'], true) ? $level : 'debug' }})">{{ __('log-insight::messages.levels.'.$level) !== 'log-insight::messages.levels.'.$level ? __('log-insight::messages.levels.'.$level) : $level }}</span>

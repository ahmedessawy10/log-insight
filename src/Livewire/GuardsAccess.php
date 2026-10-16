<?php

namespace LogInsight\Livewire;

use LogInsight\LogInsight;

/**
 * Livewire update requests don't pass through the page route's middleware chain, so every
 * component re-checks the ability on mount and before each mutating action.
 */
trait GuardsAccess
{
    protected function authorizeAbility(string $ability = 'view'): void
    {
        abort_unless(LogInsight::can(auth()->user(), $ability), 403);
    }

    protected function authorizeMutation(): void
    {
        abort_if(config('log-insight.read_only'), 403, __('log-insight::messages.common.read_only'));
        $this->authorizeAbility('manage');
    }

    public function canManage(): bool
    {
        return ! config('log-insight.read_only') && LogInsight::can(auth()->user(), 'manage');
    }
}

<?php

namespace LogInsight\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use LogInsight\Models\Tag;
use LogInsight\Models\TagRule;
use LogInsight\Security\AuditLogger;

#[Layout('log-insight::layout')]
class TagManager extends Component
{
    use GuardsAccess;

    public string $tagName = '';
    public string $field = 'message';
    public string $operator = 'contains';
    public string $value = '';

    public function mount(): void
    {
        $this->authorizeAbility('manage');
    }

    public function addRule(): void
    {
        $this->authorizeMutation();

        $this->validate([
            'tagName' => ['required', 'string', 'max:60'],
            'field' => ['required', 'in:message,channel,exception_class,stack_trace,context'],
            'operator' => ['required', 'in:contains,equals,starts_with,regex'],
            'value' => ['required', 'string', 'max:'.(int) config('log-insight.search.max_regex_length', 120)],
        ]);

        if ($this->operator === 'regex' && @preg_match('~'.str_replace('~', '\~', $this->value).'~iu', '') === false) {
            $this->addError('value', 'Invalid regular expression.');

            return;
        }

        $tag = Tag::firstOrCreate(['name' => mb_strtolower(trim($this->tagName))], ['type' => 'rule', 'color' => '#0d9488']);
        $rule = TagRule::create([
            'tag_id' => $tag->id, 'field' => $this->field, 'operator' => $this->operator, 'value' => $this->value, 'is_active' => true,
        ]);

        AuditLogger::record('tag_rule_create', (string) $rule->id, ['tag' => $tag->name]);
        $this->reset(['tagName', 'value']);
    }

    public function deleteRule(int $id): void
    {
        $this->authorizeMutation();
        TagRule::where('id', $id)->delete();
        AuditLogger::record('tag_rule_delete', (string) $id);
    }

    public function render()
    {
        $this->authorizeAbility('manage');

        return view('log-insight::livewire.tag-manager', [
            'rules' => TagRule::with('tag')->orderBy('id')->get(),
            'tags' => Tag::query()->withCount('entries')->orderByDesc('entries_count')->limit(200)->get(),
        ]);
    }
}

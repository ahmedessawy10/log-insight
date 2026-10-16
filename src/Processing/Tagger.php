<?php

namespace LogInsight\Processing;

use Illuminate\Support\Collection;
use LogInsight\Models\TagRule;

/**
 * Works out which tag names apply to an entry:
 *  1. auto tags  (level, channel, environment, exception map, context markers)
 *  2. context.tags set by the developer: Log::error('x', ['tags' => ['payment']])
 *  3. admin-defined rules (li_tag_rules)
 */
class Tagger
{
    /** @var Collection<int, TagRule>|null */
    private ?Collection $rules = null;

    public function __construct(private readonly array $exceptionMap = [], private readonly bool $auto = true) {}

    public static function fromConfig(): self
    {
        return new self(config('log-insight.tags.exception_map', []), (bool) config('log-insight.tags.auto', true));
    }

    public function reloadRules(): void
    {
        $this->rules = null;
    }

    /**
     * @param  array{level:string, environment:?string, channel:?string, message:string, exception_class:?string, stack_trace:?string}  $entry
     * @return string[] unique lowercase tag names
     */
    public function tagsFor(array $entry, ?array $context): array
    {
        $tags = [];

        if ($this->auto) {
            $tags[] = $entry['level'];
            if (! empty($entry['environment'])) {
                $tags[] = $entry['environment'];
            }
            if (! empty($entry['exception_class'])) {
                foreach ($this->exceptionMap as $needle => $tag) {
                    if (str_contains($entry['exception_class'], $needle)) {
                        $tags[] = $tag;
                    }
                }
            }
            if (is_array($context)) {
                if (isset($context['user_id']) || isset($context['userId'])) {
                    $tags[] = 'has-user';
                }
                if (isset($context['job']) || isset($context['queue'])) {
                    $tags[] = 'queue';
                }
            }
        }

        if (is_array($context) && isset($context['tags']) && is_array($context['tags'])) {
            foreach ($context['tags'] as $t) {
                if (is_string($t) && $t !== '') {
                    $tags[] = $t;
                }
            }
        }

        foreach ($this->rules() as $rule) {
            if ($this->matches($rule, $entry, $context)) {
                $tags[] = $rule->tag->name;
            }
        }

        $tags = array_map(fn ($t) => mb_substr(strtolower(trim($t)), 0, 60), $tags);

        return array_values(array_unique(array_filter($tags)));
    }

    private function rules(): Collection
    {
        return $this->rules ??= TagRule::query()->with('tag')->where('is_active', true)->get();
    }

    private function matches(TagRule $rule, array $entry, ?array $context): bool
    {
        $haystack = match ($rule->field) {
            'message' => $entry['message'],
            'channel' => (string) ($entry['channel'] ?? ''),
            'exception_class' => (string) ($entry['exception_class'] ?? ''),
            'stack_trace' => (string) ($entry['stack_trace'] ?? ''),
            'context' => $context ? json_encode($context) : '',
            default => '',
        };

        return match ($rule->operator) {
            'contains' => stripos($haystack, $rule->value) !== false,
            'equals' => strcasecmp($haystack, $rule->value) === 0,
            'starts_with' => stripos($haystack, $rule->value) === 0,
            'regex' => self::safeRegex($rule->value, $haystack),
            default => false,
        };
    }

    public static function safeRegex(string $pattern, string $subject): bool
    {
        if (strlen($pattern) > (int) config('log-insight.search.max_regex_length', 120)) {
            return false;
        }

        $delimited = '~'.str_replace('~', '\~', $pattern).'~iu';
        // Cap backtracking so a hostile pattern cannot hang the worker.
        $prev = ini_set('pcre.backtrack_limit', '100000');
        $result = @preg_match($delimited, mb_substr($subject, 0, 20000));
        if ($prev !== false) {
            ini_set('pcre.backtrack_limit', $prev);
        }

        return $result === 1;
    }
}

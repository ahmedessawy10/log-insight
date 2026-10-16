<?php

namespace LogInsight\Query;

/**
 * Parses the power-user search syntax into a LogQuery:
 *
 *   level:error,critical  tag:payment  -tag:known-bug  channel:daily  env:production
 *   exception:QueryException  user:42  request:abc123  after:2026-10-01  before:"2026-10-15 12:00"
 *   status:open|resolved|ignored  "exact phrase"  /regex/  free text
 */
class QueryLanguageParser
{
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function parse(string $input): LogQuery
    {
        $q = new LogQuery;
        $free = [];

        foreach ($this->tokenize($input) as $token) {
            if (preg_match('/^(-?)(\w+):(.+)$/s', $token, $m)) {
                $negated = $m[1] === '-';
                $key = strtolower($m[2]);
                $value = trim($m[3], '"');

                if ($this->applyKey($q, $key, $value, $negated)) {
                    continue;
                }
            }

            if (strlen($token) > 2 && $token[0] === '/' && substr($token, -1) === '/') {
                $q->regex = true;
                $q->search = substr($token, 1, -1);

                continue;
            }

            $free[] = trim($token, '"');
        }

        if ($free) {
            $q->search = trim(($q->regex ? '' : $q->search.' ').implode(' ', $free));
        }

        return $q;
    }

    private function applyKey(LogQuery $q, string $key, string $value, bool $negated): bool
    {
        $list = fn () => array_values(array_filter(array_map('trim', explode(',', $value))));

        switch ($key) {
            case 'level':
                $levels = array_map('strtolower', $list());
                $q->levels = array_values(array_intersect(self::LEVELS, $levels));
                return true;
            case 'tag':
                if ($negated) {
                    $q->tagsNone = array_merge($q->tagsNone, array_map('strtolower', $list()));
                } else {
                    // tag:a,b => any of; repeated tag: keys => all of
                    $names = array_map('strtolower', $list());
                    if (count($names) > 1) {
                        $q->tagsAny = array_merge($q->tagsAny, $names);
                    } else {
                        $q->tagsAll = array_merge($q->tagsAll, $names);
                    }
                }
                return true;
            case 'channel':
                $q->channels = $list();
                return true;
            case 'env':
            case 'environment':
                $q->environments = $list();
                return true;
            case 'exception':
                $q->exception = $value;
                return true;
            case 'has':
                if ($value === 'exception') {
                    $q->hasException = ! $negated;
                }
                return true;
            case 'http':
            case 'httpstatus':
                $q->httpStatus = $value;
                return true;
            case 'method':
                $q->httpMethod = $value;
                return true;
            case 'ip':
                $q->ip = $value;
                return true;
            case 'user':
                $q->userId = $value;
                return true;
            case 'request':
                $q->requestId = $value;
                return true;
            case 'after':
                $q->from = $value;
                return true;
            case 'before':
                $q->to = $value;
                return true;
            case 'status':
                $q->issueStatus = in_array($value, ['open', 'resolved', 'ignored'], true) ? $value : null;
                return true;
            case 'in':
                $q->inStack = $value === 'stack';
                return true;
        }

        return false;
    }

    /** Splits on whitespace but keeps "quoted phrases", key:"quoted value" and /regex with spaces/ together. */
    private function tokenize(string $input): array
    {
        preg_match_all('/(?:-?\w+:"[^"]*")|(?:"[^"]*")|(?:\/(?:\\\\.|[^\/\\\\])+\/)|\S+/', $input, $m);

        return $m[0] ?? [];
    }

    /** Turn a LogQuery back into search-box text (used when saving / displaying a filter). */
    public function toString(LogQuery $q): string
    {
        $parts = [];
        if ($q->levels) {
            $parts[] = 'level:'.implode(',', $q->levels);
        }
        foreach ($q->tagsAll as $t) {
            $parts[] = 'tag:'.$t;
        }
        if ($q->tagsAny) {
            $parts[] = 'tag:'.implode(',', $q->tagsAny);
        }
        foreach ($q->tagsNone as $t) {
            $parts[] = '-tag:'.$t;
        }
        if ($q->channels) {
            $parts[] = 'channel:'.implode(',', $q->channels);
        }
        if ($q->exception) {
            $parts[] = 'exception:'.$q->exception;
        }
        if ($q->from) {
            $parts[] = 'after:"'.$q->from.'"';
        }
        if ($q->to) {
            $parts[] = 'before:"'.$q->to.'"';
        }
        if ($q->search !== '') {
            $parts[] = $q->regex ? '/'.$q->search.'/' : (str_contains($q->search, ' ') ? '"'.$q->search.'"' : $q->search);
        }

        return implode(' ', $parts);
    }
}

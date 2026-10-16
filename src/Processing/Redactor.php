<?php

namespace LogInsight\Processing;

/**
 * Masks secrets before anything is stored: sensitive JSON/array keys, key=value pairs,
 * Bearer tokens, JWTs and Luhn-valid card numbers.
 */
class Redactor
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly array $keys = [],
        private readonly array $patterns = [],
        private readonly string $mask = '******',
    ) {}

    public static function fromConfig(): self
    {
        $c = config('log-insight.redaction');

        return new self($c['enabled'] ?? true, $c['keys'] ?? [], $c['patterns'] ?? [], $c['mask'] ?? '******');
    }

    public function redact(?string $text): ?string
    {
        if (! $this->enabled || $text === null || $text === '') {
            return $text;
        }

        if ($this->keys) {
            $keyAlt = implode('|', array_map(fn ($k) => preg_quote($k, '/'), $this->keys));

            // "password":"value"  /  "password": 123
            $text = preg_replace_callback(
                '/("(?:'.$keyAlt.')"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^,}\]\s]+)/i',
                fn ($m) => $m[1].'"'.$this->mask.'"',
                $text
            ) ?? $text;

            // password=value / token: value (query strings, form dumps). An auth scheme word
            // ("Bearer x", "Basic x") is part of the value, otherwise only the scheme would be masked.
            $text = preg_replace_callback(
                '/\b('.$keyAlt.')(\s*[=:]\s*)((?:(?:Bearer|Basic|Digest|Token)\s+)?[^\s&,"\'}]+)/i',
                fn ($m) => $m[1].$m[2].$this->mask,
                $text
            ) ?? $text;
        }

        if (in_array('bearer', $this->patterns, true)) {
            $text = preg_replace('/\b(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i', '$1'.$this->mask, $text) ?? $text;
        }

        if (in_array('jwt', $this->patterns, true)) {
            $text = preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/', $this->mask, $text) ?? $text;
        }

        if (in_array('credit_card', $this->patterns, true)) {
            $text = preg_replace_callback('/\b(?:\d[ -]?){13,19}\b/', function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);

                return strlen($digits) >= 13 && strlen($digits) <= 19 && $this->luhn($digits) ? $this->mask : $m[0];
            }, $text) ?? $text;
        }

        return $text;
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}

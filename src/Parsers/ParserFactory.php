<?php

namespace LogInsight\Parsers;

class ParserFactory
{
    /** Source types accepted in config('log-insight.sources.*.type'). */
    public const TYPES = ['laravel', 'horizon', 'nginx_access', 'nginx_error', 'apache_access', 'apache_error', 'supervisor'];

    public static function make(string $type): LogParser
    {
        return match ($type) {
            'laravel', 'horizon' => new LaravelLogParser,
            'nginx_access' => new AccessLogParser('nginx'),
            'apache_access' => new AccessLogParser('apache'),
            'nginx_error' => new NginxErrorParser,
            'apache_error' => new ApacheErrorParser,
            'supervisor' => new SupervisorParser,
            default => throw new \InvalidArgumentException("Unknown log source type [$type]."),
        };
    }
}

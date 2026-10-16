<?php

namespace LogInsight\Ingestion;

/**
 * Resolves configured sources to readable files. Every returned path is a realpath that is
 * verified to live inside the configured directory, so symlinks / ".." can't escape it.
 */
class SourceResolver
{
    /** @return array<string, string> realpath => parser type */
    public static function files(): array
    {
        $files = [];

        foreach (config('log-insight.sources', []) as $source) {
            $dir = realpath($source['path'] ?? '');
            if ($dir === false || ! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.DIRECTORY_SEPARATOR.($source['pattern'] ?? '*.log')) ?: [] as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && is_file($real) && self::inside($real, $dir)) {
                    $files[$real] = $source['type'] ?? 'laravel';
                }
            }
        }

        return $files;
    }

    /** True when the given file belongs to one of the configured sources. */
    public static function isAllowed(string $path): bool
    {
        $real = realpath($path);

        return $real !== false && array_key_exists($real, self::files());
    }

    private static function inside(string $path, string $dir): bool
    {
        $dir = rtrim($dir, '\\/').DIRECTORY_SEPARATOR;

        return str_starts_with(strtolower($path), strtolower($dir));
    }
}

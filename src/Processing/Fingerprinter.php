<?php

namespace LogInsight\Processing;

/**
 * Produces a stable hash so the "same" error groups together even when ids, timestamps,
 * quoted values or hex addresses differ between occurrences.
 */
class Fingerprinter
{
    public function normalize(string $message): string
    {
        $m = $message;
        $m = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $m);
        $m = preg_replace('/0x[0-9a-f]+/i', '<hex>', $m);
        $m = preg_replace('/\'[^\']*\'|"[^"]*"/', '<str>', $m);
        $m = preg_replace('/\d+/', '<n>', $m);
        $m = preg_replace('/\s+/', ' ', $m);

        return trim(mb_substr($m, 0, 300));
    }

    public function fingerprint(?string $exceptionClass, string $message, ?string $file, ?int $line): string
    {
        return sha1(implode('|', [
            $exceptionClass ?? '',
            $this->normalize($message),
            $file ? basename($file) : '',
            $line ?? '',
        ]));
    }
}

<?php

namespace LogInsight\Ingestion;

use Illuminate\Support\Facades\DB;
use LogInsight\Models\LogEntry;
use LogInsight\Models\LogFile;
use LogInsight\Models\LogIssue;
use LogInsight\Models\Tag;
use LogInsight\Parsers\ParserFactory;
use LogInsight\Processing\Fingerprinter;
use LogInsight\Processing\Redactor;
use LogInsight\Processing\Tagger;

/**
 * Incrementally copies new log entries from files into the database.
 * Each file remembers its byte offset, so a run only reads what was appended since the last one.
 */
class Ingestor
{
    private array $tagIds = [];

    public function __construct(
        private readonly Redactor $redactor,
        private readonly Fingerprinter $fingerprinter,
        private readonly Tagger $tagger,
    ) {}

    /**
     * @param  int|null  $maxEntries  stop after roughly this many new entries (the rest is picked up next run)
     * @return array{files:int, entries:int, more:bool}  "more" = stopped early, so there may be unread data
     */
    public function run(?int $maxEntries = null): array
    {
        $this->tagger->reloadRules();
        $stats = ['files' => 0, 'entries' => 0, 'more' => false];

        foreach (SourceResolver::files() as $path => $type) {
            $remaining = $maxEntries === null ? null : $maxEntries - $stats['entries'];
            if ($remaining !== null && $remaining <= 0) {
                $stats['more'] = true;
                break;
            }

            $stats['files']++;
            $stats['entries'] += $this->ingestFile($path, $type, $remaining, $stats['more']);
        }

        return $stats;
    }

    public function ingestFile(string $path, string $type = 'laravel', ?int $limit = null, bool &$more = false): int
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $inode = @fileinode($path) ?: null;
        $parser = ParserFactory::make($type);

        $file = LogFile::firstOrCreate(['path' => $path], ['type' => $type, 'host' => gethostname() ?: null]);

        // Rotation / truncation: file became smaller than where we stopped, or the inode changed.
        // The new file's entries start at the same byte offsets as the old ones, so it needs its own
        // LogFile row (the old row is kept, renamed, so its entries stay attached to it).
        if ($file->last_offset > $size || ($file->inode && $inode && $file->inode !== $inode)) {
            $file->update(['path' => $file->path.'#rotated-'.$file->id]);
            $file = LogFile::create(['path' => $path, 'type' => $type, 'host' => gethostname() ?: null]);
        }

        $settled = (time() - (int) filemtime($path)) >= (int) config('log-insight.ingest.settle_seconds', 2);
        $chunkSize = (int) config('log-insight.ingest.chunk_size', 500);

        $count = 0;
        $buffer = [];
        $offset = (int) $file->last_offset;

        $seen = 0;
        foreach ($parser->parse($path, $offset, $settled) as $parsed) {
            $buffer[] = $parsed;
            $seen++;

            if ($limit !== null && $seen >= $limit) {
                $more = true; // leave the rest for the next run; the saved offset only covers what was flushed
                break;
            }

            if (count($buffer) >= $chunkSize) {
                $count += $this->flush($file, $buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            $count += $this->flush($file, $buffer);
        }

        // Re-read: flush() advances last_offset per chunk.
        $file->refresh();
        $file->fill([
            'inode' => $inode,
            'size' => $size,
            'last_ingested_at' => now(),
        ])->save();

        return $count;
    }

    private function flush(LogFile $file, array $parsedEntries): int
    {
        $inserted = 0;

        // One query per chunk (not per entry) to skip anything already stored for this file.
        $existing = array_flip(LogEntry::query()
            ->where('log_file_id', $file->id)
            ->whereIn('byte_offset', array_column($parsedEntries, 'offset'))
            ->pluck('byte_offset')->all());

        DB::transaction(function () use ($file, $parsedEntries, $existing, &$inserted) {
            foreach ($parsedEntries as $parsed) {
                if (isset($existing[$parsed['offset']])) {
                    continue;
                }
                $this->store($file, $parsed);
                $inserted++;
            }

            $last = end($parsedEntries);
            $file->last_offset = $last['next_offset'];
            $file->save();
        });

        return $inserted;
    }

    private function store(LogFile $file, array $p): void
    {
        $maxMsg = (int) config('log-insight.ingest.max_message_length', 5000);
        $maxStack = (int) config('log-insight.ingest.max_stack_length', 65000);

        $full = $p['head']."\n".$p['stack'];
        [$exClass, $exMessage, $exFile, $exLine] = $this->extractException($full);

        // Exception details live inside a JSON string in the log, so quotes/backslashes arrive escaped.
        if ($exClass !== null && str_contains($p['head'], '{"exception":"')) {
            $exClass = $this->unescapeJson($exClass);
            $exMessage = $exMessage !== null ? $this->unescapeJson($exMessage) : null;
            $exFile = $exFile !== null ? $this->unescapeJson($exFile) : null;
            $p['stack'] = trim(preg_replace('/"\}\s*(\[\]|\{\})?\s*$/', '', $this->unescapeJson($p['stack'])));
        }

        $message = $this->redactor->redact(mb_substr($p['message'], 0, $maxMsg));
        $stack = $this->redactor->redact(mb_substr($p['stack'], 0, $maxStack));
        $contextRaw = $this->redactor->redact($p['context_raw']);
        $context = $contextRaw ? json_decode($contextRaw, true) : null;
        $context = is_array($context) ? $context : null;

        $fingerprintMessage = $exMessage ?: $p['message'];
        $fingerprint = $this->fingerprinter->fingerprint($exClass, $fingerprintMessage, $exFile, $exLine);

        $entry = LogEntry::create([
            'log_file_id' => $file->id,
            'logged_at' => $p['logged_at'],
            'level' => $p['level'],
            'channel' => $p['channel'],
            'environment' => $p['environment'],
            'message' => $message,
            'context' => $context,
            'stack_trace' => $stack !== '' ? $stack : null,
            'exception_class' => $exClass,
            'exception_file' => $exFile,
            'exception_line' => $exLine,
            'fingerprint' => $fingerprint,
            'request_id' => isset($context['request_id']) ? mb_substr((string) $context['request_id'], 0, 80) : null,
            'user_id' => $p['user_id'] ?? (isset($context['user_id']) ? mb_substr((string) $context['user_id'], 0, 80) : (isset($context['userId']) ? mb_substr((string) $context['userId'], 0, 80) : null)),
            'http_method' => $p['http_method'] ?? null,
            'http_status' => $p['http_status'] ?? null,
            'url' => isset($p['url']) ? $this->redactor->redact($p['url']) : null,
            'ip' => $p['ip'] ?? null,
            'byte_offset' => $p['offset'],
        ]);

        $issue = $this->touchIssue($entry, $exMessage);
        if ($issue) {
            $entry->issue_id = $issue->id;
            $entry->saveQuietly();
        }

        $names = $this->tagger->tagsFor([
            'level' => $entry->level,
            'environment' => $entry->environment,
            'channel' => $entry->channel,
            'message' => $entry->message,
            'exception_class' => $entry->exception_class,
            'stack_trace' => $entry->stack_trace,
        ], $context);

        if ($names) {
            $entry->tags()->attach(array_map(fn ($n) => $this->tagId($n), $names));
        }
    }

    /** One issue row per fingerprint for error-level (or exception) entries. */
    private function touchIssue(LogEntry $entry, ?string $exMessage): ?LogIssue
    {
        if (! $entry->exception_class && ! in_array($entry->level, config('log-insight.error_levels', []), true)) {
            return null;
        }

        $issue = LogIssue::firstOrNew(['fingerprint' => $entry->fingerprint]);

        if (! $issue->exists) {
            $issue->fill([
                'title' => mb_substr($exMessage ?: $entry->message, 0, 500),
                'exception_class' => $entry->exception_class,
                'level' => $entry->level,
                'first_seen_at' => $entry->logged_at,
                'last_seen_at' => $entry->logged_at,
                'occurrences' => 1,
                'status' => 'open',
            ])->save();

            return $issue;
        }

        $issue->occurrences++;
        if (! $issue->last_seen_at || $entry->logged_at->gt($issue->last_seen_at)) {
            $issue->last_seen_at = $entry->logged_at;
        }
        if (! $issue->first_seen_at || $entry->logged_at->lt($issue->first_seen_at)) {
            $issue->first_seen_at = $entry->logged_at;
        }
        // A resolved issue that shows up again after it was resolved is a regression.
        if ($issue->status === 'resolved' && $issue->resolved_at && $entry->logged_at->gt($issue->resolved_at)) {
            $issue->status = 'open';
            $issue->regressed_at = $entry->logged_at;
        }
        $issue->save();

        return $issue;
    }

    /** @return array{0:?string, 1:?string, 2:?string, 3:?int} class, message, file, line */
    private function extractException(string $text): array
    {
        if (preg_match('/\[object\] \(([\w\\\\]+)\(code: [^)]*\): (.*?) at (.+?):(\d+)\)/s', $text, $m)) {
            return [$m[1], trim($m[2]), $m[3], (int) $m[4]];
        }

        return [null, null, null, null];
    }

    private function unescapeJson(string $s): string
    {
        return strtr($s, ['\\\\' => '\\', '\\"' => '"', '\\/' => '/']);
    }

    private function tagId(string $name): int
    {
        return $this->tagIds[$name] ??= Tag::firstOrCreate(
            ['name' => $name],
            ['color' => $this->colorFor($name), 'type' => 'auto']
        )->id;
    }

    private function colorFor(string $name): string
    {
        $fixed = [
            'emergency' => '#7f1d1d', 'alert' => '#991b1b', 'critical' => '#b91c1c', 'error' => '#dc2626',
            'warning' => '#d97706', 'notice' => '#0891b2', 'info' => '#2563eb', 'debug' => '#6b7280',
        ];

        return $fixed[$name] ?? sprintf('#%06x', crc32($name) & 0x7f7f7f | 0x404040);
    }
}

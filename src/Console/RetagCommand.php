<?php

namespace LogInsight\Console;

use Illuminate\Console\Command;
use LogInsight\Models\LogEntry;
use LogInsight\Models\Tag;
use LogInsight\Processing\Tagger;

class RetagCommand extends Command
{
    protected $signature = 'log-insight:retag';

    protected $description = 'Re-apply tag rules to all stored entries (run after adding or changing rules)';

    public function handle(Tagger $tagger): int
    {
        $count = 0;

        LogEntry::query()->with('tags')->chunkById(500, function ($entries) use ($tagger, &$count) {
            foreach ($entries as $entry) {
                $names = $tagger->tagsFor([
                    'level' => $entry->level,
                    'environment' => $entry->environment,
                    'channel' => $entry->channel,
                    'message' => $entry->message,
                    'exception_class' => $entry->exception_class,
                    'stack_trace' => $entry->stack_trace,
                ], $entry->context);

                $ids = array_map(fn ($n) => Tag::firstOrCreate(['name' => $n], ['type' => 'rule'])->id, $names);
                // Keep manual tags, add anything newly matched.
                $entry->tags()->syncWithoutDetaching($ids);
                $count++;
            }
        });

        $this->info("Re-tagged $count entries.");

        return self::SUCCESS;
    }
}

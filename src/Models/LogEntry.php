<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LogEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'li_entries';

    protected $guarded = [];

    protected $casts = [
        'logged_at' => 'datetime',
        'context' => 'array',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(LogFile::class, 'log_file_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(LogIssue::class, 'issue_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'li_entry_tag', 'entry_id', 'tag_id');
    }
}

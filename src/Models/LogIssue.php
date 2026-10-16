<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogIssue extends Model
{
    protected $table = 'li_issues';

    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'regressed_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LogEntry::class, 'issue_id');
    }
}

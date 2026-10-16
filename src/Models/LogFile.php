<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogFile extends Model
{
    protected $table = 'li_log_files';

    protected $guarded = [];

    protected $casts = ['last_ingested_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(LogEntry::class, 'log_file_id');
    }
}

<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $table = 'li_tags';

    protected $guarded = [];

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(LogEntry::class, 'li_entry_tag', 'tag_id', 'entry_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TagRule::class, 'tag_id');
    }
}

<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagRule extends Model
{
    protected $table = 'li_tag_rules';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}

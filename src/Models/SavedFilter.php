<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    protected $table = 'li_saved_filters';

    protected $guarded = [];

    protected $casts = ['is_shared' => 'boolean'];
}

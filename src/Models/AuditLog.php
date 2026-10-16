<?php

namespace LogInsight\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'li_audit_logs';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}

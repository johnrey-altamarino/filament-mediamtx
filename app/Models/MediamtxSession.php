<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediamtxSession extends Model
{
    protected $fillable = [
        'type', 'session_id', 'state', 'path', 'query', 'remote_addr', 'ip', 'created',
        'user_agent', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];
}

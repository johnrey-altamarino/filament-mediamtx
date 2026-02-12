<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediamtxSession extends Model
{
    protected $fillable = [
        'type','session_id','state','path','query','remote_addr','ip','created',
        'bytes_received','bytes_sent','last_seen_at',
    ];

    protected $casts = [
        'bytes_received' => 'integer',
        'bytes_sent' => 'integer',
        'last_seen_at' => 'datetime',
    ];
}

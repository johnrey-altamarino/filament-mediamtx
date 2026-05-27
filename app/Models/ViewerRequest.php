<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ViewerRequest extends Model
{
    protected $fillable = [
        'ip', 'path', 'referer', 'user_agent',
        'os', 'browser', 'device', 'device_model',
        'allowed', 'deny_reason', 'requested_at',
    ];

    protected $casts = [
        'allowed' => 'boolean',
        'requested_at' => 'datetime',
    ];
}

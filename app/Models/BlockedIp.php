<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $fillable = [
        'ip',
        'reason',
        'blocked_by_user_id',
        'blocked_at',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
    ];

    public function blockedBy()
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }
}

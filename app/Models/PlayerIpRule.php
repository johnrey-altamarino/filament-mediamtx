<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerIpRule extends Model
{
    protected $fillable = ['ip', 'label'];

    protected static function booted(): void
    {
        $flush = fn () => cache()->forget('player_ip_rules');
        static::created($flush);
        static::updated($flush);
        static::deleted($flush);
    }
}

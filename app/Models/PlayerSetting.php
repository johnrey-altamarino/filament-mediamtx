<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        $flush = fn () => cache()->forget('player_settings');
        static::created($flush);
        static::updated($flush);
        static::deleted($flush);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}

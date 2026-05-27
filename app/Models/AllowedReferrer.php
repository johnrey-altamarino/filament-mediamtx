<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedReferrer extends Model
{
    public function matches(string $referer): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->is_regex) {
            return @preg_match($this->pattern, $referer) === 1;
        }

        // Wildcard mode: https://*.armtech.one
        $pattern = preg_quote($this->pattern, '~');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = '^'.$pattern.'(/|$)';

        return preg_match('~'.$pattern.'~i', $referer) === 1;
    }

    protected $fillable = [
        'name',
        'pattern',
        'is_regex',
        'is_enabled',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_regex' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

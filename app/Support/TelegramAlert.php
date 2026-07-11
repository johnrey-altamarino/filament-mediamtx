<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class TelegramAlert
{
    /**
     * HTML-escape an operator/user-controlled field for parse_mode=HTML messages.
     */
    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Per-minute flood cap. Returns false once more than $cap calls have occurred
     * for $bucket within the current wall-clock minute.
     */
    public static function floodCap(string $bucket, int $cap, int $ttl = 65): bool
    {
        $key = "notify:flood:{$bucket}:".now()->format('YmdHi');

        Cache::add($key, 0, $ttl);

        return Cache::increment($key) <= $cap;
    }
}

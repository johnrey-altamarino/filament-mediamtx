<?php

namespace App\Support;

use Closure;

class DashboardCache
{
    public static function remember(string $key, int $seconds, Closure $callback)
    {
        return cache()->remember(
            $key,
            now()->addSeconds($seconds),
            $callback
        );
    }

    public static function key(string $name, array $parts = []): string
    {
        // stable cache key with params
        $suffix = $parts ? ':'.sha1(json_encode($parts)) : '';

        return "dash:{$name}{$suffix}";
    }
}

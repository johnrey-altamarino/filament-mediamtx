<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('token-read', function (Request $request) {
            $ip = $request->header('cf-connecting-ip')
                ?: ($request->header('x-forwarded-for') ? trim(explode(',', $request->header('x-forwarded-for'))[0]) : $request->ip());

            // tuned for “token minting”: keep it tight
            return [
                Limit::perMinute(60)->by($ip),          // 60/min per IP
                Limit::perMinute(600)->by('global'),    // global circuit breaker
            ];
        });
    }
}

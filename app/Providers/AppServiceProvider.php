<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Services\ClientIpResolver;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('mediamtx.jwt.private_key', function () {
            $path = (string) config('services.mediamtx_jwt.private_key_path', 'storage/app/jwt/private.pem');
            $absolutePath = base_path($path);

            if (! is_readable($absolutePath)) {
                throw new RuntimeException("JWT private key not found or not readable: {$absolutePath}");
            }

            $key = file_get_contents($absolutePath);

            if ($key === false || $key === '') {
                throw new RuntimeException('JWT private key is empty');
            }

            return $key;
        });
    }

    public function boot(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);

        RateLimiter::for('token-read', function (Request $request) {
            $ip = app(ClientIpResolver::class)->resolve($request);

            // tuned for “token minting”: keep it tight
            return [
                Limit::perMinute(60)->by($ip),          // 60/min per IP
                Limit::perMinute(600)->by('global'),    // global circuit breaker
            ];
        });
    }
}

<?php

use App\Http\Middleware\AllowMediamtxOnly;
use App\Http\Middleware\PlayerAccessMiddleware;
use App\Http\Middleware\VerifyMediamtxWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS terminates at the proxy (Cloudflare/Apache); PHP is reached over
        // plain HTTP. Honour X-Forwarded-Proto so Laravel/Livewire generate
        // https URLs instead of mixed-content http ones.
        //
        // NOTE: we deliberately do NOT trust X-Forwarded-For here. Client IP
        // resolution is handled separately by ClientIpResolver, which has its
        // own trusted-proxy model to prevent IP allowlist/blocklist spoofing.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->redirectTo(function (Request $request) {
            try {
                return route('login');
            } catch (\Exception $e) {
                return '/login';
            }
        });

        $middleware->alias([
            'jwks.ip' => AllowMediamtxOnly::class,
            'player.access' => PlayerAccessMiddleware::class,
            'mediamtx.webhook' => VerifyMediamtxWebhook::class,
        ]);
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

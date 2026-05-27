<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowMediamtxOnly
{
    public function handle(Request $request, Closure $next)
    {
        // Real client IP (Cloudflare safe)
        $ip = $request->header('cf-connecting-ip') ?? $request->ip() ?? $request->getClientIp('X-Forwarded-For') ?? $request->server('REMOTE_ADDR');

        $allowedIps = collect(explode(',', config('services.mediamtx.allowed_ips', '')))
            ->map(fn ($ip) => trim($ip))
            ->filter()
            ->values()
            ->all();

        if (! in_array($ip, $allowedIps, true)) {
            abort(403);
        }

        return $next($request);
    }
}

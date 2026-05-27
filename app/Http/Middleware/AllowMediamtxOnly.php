<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowMediamtxOnly
{
    public function handle(Request $request, Closure $next)
    {
        $ip = $this->clientIp($request);

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

    private function clientIp(Request $request): ?string
    {
        // Cloudflare (when deployed behind CF)
        $cf = $request->headers->get('cf-connecting-ip');
        if ($cf) {
            return $cf;
        }

        // Proxies
        $xff = $request->headers->get('x-forwarded-for');
        if ($xff) {
            $parts = array_map('trim', explode(',', $xff));

            return $parts[0] ?? $request->ip();
        }

        return $request->ip();
    }
}

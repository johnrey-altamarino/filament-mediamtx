<?php

namespace App\Http\Middleware;

use App\Services\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;

class AllowMediamtxOnly
{
    public function __construct(private readonly ClientIpResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        $ip = $this->resolver->resolve($request);

        $allowedIps = collect(explode(',', config('services.mediamtx.allowed_ips', '')))
            ->map(fn ($ip) => trim($ip))
            ->filter()
            ->values()
            ->all();

        // Note: if MEDIAMTX_ALLOWED_IPS is unset/empty, all requests are denied (fail-closed by design).
        if (! in_array($ip, $allowedIps, true)) {
            abort(403);
        }

        return $next($request);
    }
}

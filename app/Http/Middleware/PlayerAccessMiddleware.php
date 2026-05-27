<?php

namespace App\Http\Middleware;

use App\Models\PlayerIpRule;
use App\Models\PlayerSetting;
use App\Services\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;

class PlayerAccessMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $mode = cache()->remember(
            'player_settings',
            30,
            fn () => PlayerSetting::get('ip_mode', 'blacklist')
        );

        $rules = cache()->remember(
            'player_ip_rules',
            30,
            fn () => PlayerIpRule::pluck('ip')->all()
        );

        $ip = app(ClientIpResolver::class)->resolve($request);

        if ($mode === 'whitelist') {
            // 0.0.0.0 means allow all
            if (in_array('0.0.0.0', $rules, true)) {
                return $next($request);
            }

            if (! in_array($ip, $rules, true)) {
                abort(403);
            }
        } else {
            // blacklist mode: block listed IPs, allow all others
            if (in_array($ip, $rules, true)) {
                abort(403);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\AllowedReferrer;
use App\Models\PlayerIpRule;
use App\Models\PlayerSetting;
use App\Services\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlayerAccessMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Loaded up front (not just for the pass-through path) so every 403 this
        // middleware returns carries the same CSP frame-ancestors header as a
        // successful response — the header must not distinguish which gate fired.
        $referrers = cache()->remember(
            'allowed_referrers',
            30,
            fn () => AllowedReferrer::query()->where('is_enabled', true)->get()
        );

        // ── IP gate ───────────────────────────────────────────────────────────
        $mode = cache()->remember(
            'player_settings',
            30,
            fn () => PlayerSetting::get('ip_mode', 'blacklist')
        );

        $ipRules = cache()->remember(
            'player_ip_rules',
            30,
            fn () => PlayerIpRule::pluck('ip')->all()
        );

        $ip = app(ClientIpResolver::class)->resolve($request);

        if ($mode === 'whitelist') {
            // 0.0.0.0 means allow all
            if (! in_array('0.0.0.0', $ipRules, true) && ! in_array($ip, $ipRules, true)) {
                return $this->deny($referrers);
            }
        } else {
            // blacklist mode: block listed IPs, allow all others
            if (in_array($ip, $ipRules, true)) {
                return $this->deny($referrers);
            }
        }

        // ── Referrer / embedding gate ─────────────────────────────────────────
        // Only enforce when the browser signals this is an iframe embed.
        // Sec-Fetch-Dest: iframe is sent by all modern browsers for framed loads;
        // direct navigation sends "document", so legitimate direct access is unaffected.
        if ($request->headers->get('sec-fetch-dest') === 'iframe') {
            $origin = (string) $request->headers->get('origin', '');
            $referer = (string) $request->headers->get('referer', '');
            // Prefer Origin (exact host, no path leakage); fall back to Referer
            $source = ($origin !== '' && $origin !== 'null') ? $origin : $referer;
            $appUrl = rtrim((string) config('app.url'), '/');

            if ($source !== '' && ! str_starts_with($source, $appUrl)) {
                $allowed = $referrers->contains(fn ($rule) => $rule->matches($source));
                if (! $allowed) {
                    return $this->deny($referrers);
                }
            }
        }

        // ── Pass through + set CSP frame-ancestors ────────────────────────────
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            $this->frameAncestorsHeader($referrers)
        );

        return $response;
    }

    private function deny(iterable $referrers): Response
    {
        $response = response()->view('errors.stream-unavailable', [], 403);

        $response->headers->set(
            'Content-Security-Policy',
            $this->frameAncestorsHeader($referrers)
        );

        return $response;
    }

    /**
     * Build the allowed-origins list from non-regex referrer patterns.
     * Regex patterns cannot be represented as CSP host-sources, so they are
     * excluded (the server-side check elsewhere in this middleware still covers them).
     */
    private function frameAncestorsHeader(iterable $referrers): string
    {
        $frameAncestors = ["'self'"];
        foreach ($referrers as $rule) {
            if (! $rule->is_regex) {
                $frameAncestors[] = $rule->pattern;
            }
        }

        return 'frame-ancestors '.implode(' ', $frameAncestors);
    }
}

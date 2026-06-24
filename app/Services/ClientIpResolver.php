<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class ClientIpResolver
{
    /**
     * Resolve the real client IP address.
     *
     * Forwarding headers (CF-Connecting-IP, X-Forwarded-For) are attacker-controllable,
     * so they are only honoured when the direct peer ($request->ip()) is a configured
     * trusted proxy. Otherwise the peer address is used, preventing IP-allowlist /
     * blocklist spoofing. Configure trusted proxies via MEDIAMTX_TRUSTED_PROXIES
     * (comma-separated IPs/CIDRs, or "*" to trust any peer).
     *
     * When the peer is trusted, priority is:
     *   1. CF-Connecting-IP (Cloudflare forwards the real client here)
     *   2. X-Forwarded-For leftmost entry
     *   3. the peer address
     */
    public function resolve(Request $request): string
    {
        $peer = $request->ip() ?? '0.0.0.0';

        if (! $this->peerIsTrustedProxy($peer)) {
            return $peer;
        }

        $cf = $request->headers->get('cf-connecting-ip');
        if ($cf) {
            return $cf;
        }

        $xff = $request->headers->get('x-forwarded-for');
        if ($xff) {
            $parts = array_map('trim', explode(',', $xff));

            return $parts[0] ?: $peer;
        }

        return $peer;
    }

    private function peerIsTrustedProxy(string $peer): bool
    {
        $proxies = collect(explode(',', (string) config('services.mediamtx.trusted_proxies', '')))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->values()
            ->all();

        if ($proxies === []) {
            return false;
        }

        if (in_array('*', $proxies, true)) {
            return true;
        }

        return IpUtils::checkIp($peer, $proxies);
    }
}

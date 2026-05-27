<?php

namespace App\Services;

use Illuminate\Http\Request;

class ClientIpResolver
{
    /**
     * Resolve the real client IP address, honouring Cloudflare and proxy headers.
     *
     * Priority:
     *   1. CF-Connecting-IP (Cloudflare terminates TLS and forwards this)
     *   2. X-Forwarded-For leftmost entry (first proxy added by legitimate proxies)
     *   3. $request->ip() fallback (direct connection or trusted proxy)
     */
    public function resolve(Request $request): string
    {
        $cf = $request->headers->get('cf-connecting-ip');
        if ($cf) {
            return $cf;
        }

        $xff = $request->headers->get('x-forwarded-for');
        if ($xff) {
            $parts = array_map('trim', explode(',', $xff));

            return $parts[0] ?: ($request->ip() ?? '0.0.0.0');
        }

        return $request->ip() ?? '0.0.0.0';
    }
}

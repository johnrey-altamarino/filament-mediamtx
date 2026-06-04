<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyMediamtxWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('services.mediamtx.webhook_secret', '');
        $provided = (string) $request->header('X-Webhook-Secret', '');

        // Fail-closed: no configured secret means deny everything.
        if ($configured === '' || ! hash_equals($configured, $provided)) {
            abort(403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedReferrer;
use App\Models\BlockedIp;
use App\Services\ClientIpResolver;
use App\Services\MediamtxJwtIssuer;
use App\Services\ViewerRequestLogger;
use Illuminate\Http\Request;

class ReadTokenController extends Controller
{
    public function __construct(
        private readonly MediamtxJwtIssuer $issuer,
        private readonly ClientIpResolver $resolver,
        private readonly ViewerRequestLogger $logger,
    ) {}

    public function __invoke(Request $request)
    {

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'], // MediaMTX path, e.g. "live/stream1"
        ]);
        $path = $data['path'];
        $ip = $this->resolver->resolve($request);
        $referer = (string) $request->headers->get('referer', '');

        // 1) Blocked IP gate
        $isBlocked = $ip && cache()->remember(
            'blocked_ip:'.$ip,
            now()->addSeconds(30),
            fn () => BlockedIp::where('ip', $ip)->exists()
        );
        if ($isBlocked) {
            $this->logger->logIfNotThrottled($request, $ip, $path, false, 'blocked_ip');

            activity('security')
                ->withProperties(['ip' => $ip, 'referer' => $referer, 'path' => $path])
                ->log('token.read.denied.blocked_ip');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        // 2) Strict referer gate
        if ($referer === '' || ! $this->refererAllowed($referer)) {
            $this->logger->logIfNotThrottled($request, $ip, $path, false, 'bad_referer');

            activity('security')
                ->withProperties(['ip' => $ip, 'referer' => $referer, 'path' => $path])
                ->log('token.read.denied.bad_referer');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        // 3) Issue read JWT via service
        try {
            $tokenData = $this->issuer->issueReadToken($path);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to issue token'], 500);
        }

        activity('security')
            ->causedBy(auth()->user() ?? null)
            ->withProperties(['ip' => $ip, 'referer' => $referer, 'path' => $path, 'exp' => $tokenData['expires_at']])
            ->log('token.read.issued');

        $this->logger->logIfNotThrottled($request, $ip, $path, true, null);

        return response()->json($tokenData);
    }

    private function refererAllowed(string $referer): bool
    {
        // The player page always fetches tokens from its own origin (APP_URL/play),
        // whether loaded directly or in an iframe. Iframe protection lives in
        // PlayerAccessMiddleware; direct-playback control lives in PlayerController.
        if (str_starts_with($referer, rtrim((string) config('app.url'), '/'))) {
            return true;
        }

        $rules = cache()->remember(
            'allowed_referrers',
            now()->addSeconds(30),
            fn () => AllowedReferrer::query()->where('is_enabled', true)->get()
        );

        foreach ($rules as $rule) {
            if ($rule->matches($referer)) {
                return true;
            }
        }

        return false;
    }
}

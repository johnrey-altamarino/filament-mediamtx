<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedReferrer;
use App\Models\BlockedIp;
use App\Models\ViewerRequest;
use App\Services\MediamtxJwtIssuer;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;

class ReadTokenController extends Controller
{
    public function __construct(private readonly MediamtxJwtIssuer $issuer) {}

    public function __invoke(Request $request)
    {

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'], // MediaMTX path, e.g. "live/stream1"
        ]);
        $path = $data['path'];
        $ip = $this->clientIp($request);
        $referer = (string) $request->headers->get('referer', '');

        // 1) Blocked IP gate
        $isBlocked = $ip && cache()->remember(
            'blocked_ip:' . $ip,
            now()->addSeconds(30),
            fn () => BlockedIp::where('ip', $ip)->exists()
        );
        if ($isBlocked) {

            if ($this->shouldLogViewerRequest($request, $ip, $path)) {
                $this->logViewerRequest($request, $ip, $path, false, 'blocked_ip');
            }

            activity('security')
                ->withProperties(['ip' => $ip, 'referer' => $referer, 'path' => $path])
                ->log('token.read.denied.blocked_ip');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        // 2) Strict referer gate
        if ($referer === '' || ! $this->refererAllowed($referer)) {
            if ($this->shouldLogViewerRequest($request, $ip, $path)) {
                $this->logViewerRequest($request, $ip, $path, false, 'bad_referer');
            }

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

        if ($this->shouldLogViewerRequest($request, $ip, $path)) {
            $this->logViewerRequest($request, $ip, $path, true, null);
        }

        return response()->json($tokenData);
    }

    private function refererAllowed(string $referer): bool
    {
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

    private function clientIp(Request $request): ?string
    {
        // Cloudflare (when you deploy behind CF)
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

    private function logViewerRequest(
        Request $request,
        string $ip,
        string $path,
        bool $allowed,
        ?string $denyReason = null
    ): void {
        $ua = (string) $request->userAgent();
        $referer = (string) $request->headers->get('referer', '');

        $agent = new Agent;
        $agent->setUserAgent($ua);

        $device = 'Unknown';
        if ($agent->isDesktop()) {
            $device = 'Desktop';
        } elseif ($agent->isTablet()) {
            $device = 'Tablet';
        } elseif ($agent->isMobile()) {
            $device = 'Mobile';
        } elseif ($agent->isRobot()) {
            $device = 'Bot';
        }

        ViewerRequest::create([
            'ip' => $ip,
            'path' => $path,
            'referer' => $referer ?: null,
            'user_agent' => $ua ?: null,

            'os' => $agent->platform() ?: 'Unknown',
            'browser' => $agent->browser() ?: 'Unknown',
            'device' => $device,
            'device_model' => $agent->device() ?: null,

            'allowed' => $allowed,
            'deny_reason' => $denyReason,
            'requested_at' => now(),
        ]);
    }

    private function shouldLogViewerRequest(Request $request, string $ip, string $path): bool
    {
        $seconds = (int) config('services.viewer_log.throttle_seconds', 10);
        if ($seconds <= 0) {
            return true;
        }

        $mode = (string) config('services.viewer_log.throttle_mode', 'ip_path_ua');

        $ua = (string) $request->userAgent();
        $ref = (string) $request->headers->get('referer', '');

        $keyParts = match ($mode) {
            'ip_path' => [$ip, $path],
            'ip_path_ref' => [$ip, $path, $ref],
            default => [$ip, $path, $ua], // ip_path_ua
        };

        $key = 'vr:log:'.sha1(implode('|', $keyParts));

        // Cache:add returns true only if key did NOT exist.
        // So we only log once per TTL window for that fingerprint.
        return cache()->add($key, 1, now()->addSeconds($seconds));
    }
}

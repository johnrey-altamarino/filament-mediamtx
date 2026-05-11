<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedReferrer;
use App\Models\BlockedIp;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\ViewerRequest;
use Jenssegers\Agent\Agent;

class ReadTokenController extends Controller
{
    public function __invoke(Request $request)
    {

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'], // MediaMTX path, e.g. "live/stream1"
        ]);
        $path = $data['path'];
        $ip = $this->clientIp($request);
        $referer = (string) $request->headers->get('referer', '');

        // 1) Blocked IP gate
        if ($ip && BlockedIp::where('ip', $ip)->exists()) {

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

        // 3) Build MediaMTX JWT
        $now = time();
        $ttl = config('services.mediamtx_jwt.ttl', 60);
        $kid = (string) config('services.mediamtx_jwt.kid', 'mediamtx-1');
        $iss = (string) (config('services.mediamtx_jwt.iss') ?: config('app.url'));

        $privateKeyPath = (string) config('services.mediamtx_jwt.private_key_path', 'storage/app/jwt/private.pem');
        $privateKey = @file_get_contents(base_path($privateKeyPath));

        if (! is_string($privateKey) || $privateKey === '') {
            return response()->json(['message' => 'JWT private key not configured'], 500);
        }

        $payload = [
            'iss' => $iss,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => (string) Str::uuid(),

            // MediaMTX default claim key is "mediamtx_permissions" :contentReference[oaicite:3]{index=3}
            'mediamtx_permissions' => [
                [
                    'action' => 'read',
                    'path' => $data['path'],
                ],
            ],
        ];

        $jwt = JWT::encode($payload, $privateKey, 'RS256', $kid);

        activity('security')
            ->causedBy(auth()->user() ?? null)
            ->withProperties(['ip' => $ip, 'referer' => $referer, 'path' => $data['path'], 'exp' => $payload['exp']])
            ->log('token.read.issued');

        if ($this->shouldLogViewerRequest($request, $ip, $path)) {
            $this->logViewerRequest($request, $ip, $path, true, null);
        }


        return response()->json([
            'id' => $payload['jti'],
            'token' => $jwt,
            'expires_at' => $payload['exp'],
            'expires_at_iso' => now()->setTimestamp($payload['exp'])->toIso8601String(),
        ]);
    }

    private function refererAllowed(string $referer): bool
    {
        $rules = AllowedReferrer::query()
            ->where('is_enabled', true)
            ->get();

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
            if ($cf) return $cf;

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

        $agent = new Agent();
        $agent->setUserAgent($ua);

        $device = 'Unknown';
        if ($agent->isDesktop()) $device = 'Desktop';
        elseif ($agent->isTablet()) $device = 'Tablet';
        elseif ($agent->isMobile()) $device = 'Mobile';
        elseif ($agent->isRobot()) $device = 'Bot';

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
        if ($seconds <= 0) return true;

        $mode = (string) config('services.viewer_log.throttle_mode', 'ip_path_ua');

        $ua = (string) $request->userAgent();
        $ref = (string) $request->headers->get('referer', '');

        $keyParts = match ($mode) {
            'ip_path' => [$ip, $path],
            'ip_path_ref' => [$ip, $path, $ref],
            default => [$ip, $path, $ua], // ip_path_ua
        };

        $key = 'vr:log:' . sha1(implode('|', $keyParts));

        // Cache:add returns true only if key did NOT exist.
        // So we only log once per TTL window for that fingerprint.
        return cache()->add($key, 1, now()->addSeconds($seconds));
    }

}

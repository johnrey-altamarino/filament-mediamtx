<?php

namespace App\Services;

use App\Models\ViewerRequest;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;

class ViewerRequestLogger
{
    public function logIfNotThrottled(
        Request $request,
        string $ip,
        string $path,
        bool $allowed,
        ?string $denyReason = null
    ): void {
        if (! $this->shouldLog($request, $ip, $path)) {
            return;
        }

        $this->log($request, $ip, $path, $allowed, $denyReason);
    }

    private function shouldLog(Request $request, string $ip, string $path): bool
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

        // Cache::add returns true only if key did NOT exist.
        // So we only log once per TTL window for that fingerprint.
        return cache()->add($key, 1, now()->addSeconds($seconds));
    }

    private function log(
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
}

<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class MediamtxApi
{
    private function http(): PendingRequest
    {
        $base = rtrim(config('services.mediamtx.api_base_url', 'http://127.0.0.1:9997'), '/');

        $req = Http::baseUrl($base)
            ->timeout(config('services.mediamtx.api_timeout', 3))
            ->acceptJson();

        $user = config('services.mediamtx.api_user');
        $pass = config('services.mediamtx.api_pass');

        if ($user && $pass) {
            $req = $req->withBasicAuth($user, $pass);
        }

        return $req;
    }

    public function listWebRtcSessions(): array
    {
        return $this->http()->get('/v3/webrtcsessions/list')->throw()->json();
    }

    public function listPaths(): array
    {
        return $this->http()->get('/v3/paths/list')->throw()->json();
    }

    public function kickWebRtc(string $id): void
    {
        $this->http()->post("/v3/webrtcsessions/kick/{$id}")->throw();
    }

    /**
     * Resolve client fields for a live WebRTC session by its id:
     * ['ip' => ..., 'userAgent' => ...].
     *
     * Used to enrich webhook alerts, since MediaMTX hooks expose neither the IP
     * nor the User-Agent. Best-effort: both are '' when the id is unknown, the
     * session has already ended, or the API is unreachable.
     */
    public function findSession(string $id): array
    {
        $empty = ['ip' => '', 'userAgent' => ''];

        if ($id === '') {
            return $empty;
        }

        try {
            $items = data_get($this->listWebRtcSessions(), 'items', []);
        } catch (Throwable $e) {
            return $empty;
        }

        foreach ($items as $it) {
            if ((string) data_get($it, 'id', '') === $id) {
                return [
                    'ip' => self::clientIp($it),
                    'userAgent' => (string) data_get($it, 'userAgent', ''),
                ];
            }
        }

        return $empty;
    }

    /**
     * Resolve just the client IP for a live WebRTC session by its id.
     * Returns '' when unknown — the caller falls back to "unknown".
     */
    public function findSessionIp(string $id): string
    {
        return $this->findSession($id)['ip'];
    }

    /**
     * Resolve the real client IP for a WebRTC session.
     *
     * Prefer the ICE remote candidate: the media (UDP) path connects directly to the
     * origin, bypassing any HTTP proxy (Cloudflare/Apache) that fronts the WHEP
     * signaling, so the candidate carries the viewer's true address — typically IPv4,
     * even when `remoteAddr` (the signaling peer) is a proxy/IPv6 address. The
     * candidate is only populated once the peer connection is established; until then
     * fall back to `remoteAddr`.
     */
    public static function clientIp(array $session): string
    {
        $candidate = self::ipFromCandidate((string) data_get($session, 'remoteCandidate', ''));

        return $candidate !== ''
            ? $candidate
            : self::ipFromRemoteAddr((string) data_get($session, 'remoteAddr', ''));
    }

    /**
     * Extract the IP from a MediaMTX ICE candidate ("prflx/udp/49.146.39.58/10119").
     * Returns '' when the candidate is empty or does not hold a valid IP.
     */
    public static function ipFromCandidate(string $candidate): string
    {
        $ip = explode('/', $candidate)[2] ?? '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Extract the bare IP from a MediaMTX remoteAddr ("1.2.3.4:5555" or "[::1]:5555").
     */
    public static function ipFromRemoteAddr(string $remoteAddr): string
    {
        if (str_starts_with($remoteAddr, '[')) {
            $end = strpos($remoteAddr, ']');

            return $end !== false ? substr($remoteAddr, 1, $end - 1) : $remoteAddr;
        }

        if (substr_count($remoteAddr, ':') === 1) {
            return explode(':', $remoteAddr, 2)[0];
        }

        return $remoteAddr;
    }
}

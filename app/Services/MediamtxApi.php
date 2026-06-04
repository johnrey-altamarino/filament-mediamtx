<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

    public function kickWebRtc(string $id): void
    {
        $this->http()->post("/v3/webrtcsessions/kick/{$id}")->throw();
    }

    /**
     * Resolve the client IP for a live WebRTC session by its id.
     *
     * Used to enrich webhook alerts, since MediaMTX hooks don't expose the IP.
     * Best-effort: returns '' when the id is unknown, the session has already
     * ended, or the API is unreachable — the caller falls back to "unknown".
     */
    public function findSessionIp(string $id): string
    {
        if ($id === '') {
            return '';
        }

        try {
            $items = data_get($this->listWebRtcSessions(), 'items', []);
        } catch (\Throwable $e) {
            return '';
        }

        foreach ($items as $it) {
            if ((string) data_get($it, 'id', '') === $id) {
                return self::ipFromRemoteAddr((string) data_get($it, 'remoteAddr', ''));
            }
        }

        return '';
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

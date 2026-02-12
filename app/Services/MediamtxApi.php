<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MediamtxApi
{
    private function http()
    {
        $base = rtrim(env('MEDIAMTX_API_BASE_URL', 'http://127.0.0.1:9997'), '/');

        $req = Http::baseUrl($base)
            ->timeout((int) env('MEDIAMTX_API_TIMEOUT', 3))
            ->acceptJson();

        $user = env('MEDIAMTX_API_USER');
        $pass = env('MEDIAMTX_API_PASS');

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
}

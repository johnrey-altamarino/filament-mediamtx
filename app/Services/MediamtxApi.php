<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MediamtxApi
{
    private function http()
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
}

<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

class MediamtxJwtIssuer
{
    public function issuePublishToken(string $path, bool $noExpiry = true): array
    {
        $now = time();

        // "No expiry" implemented as far-future exp (10 years).
        $exp = $noExpiry ? ($now + 60 * 60 * 24 * 365 * 10) : ($now + config('services.mediamtx_jwt.ttl', 60));

        $kid = (string) config('services.mediamtx_jwt.kid', 'mediamtx-1');
        $iss = (string) (config('services.mediamtx_jwt.iss') ?: config('app.url'));

        $privateKey = app('mediamtx.jwt.private_key');

        $payload = [
            'iss' => $iss,
            'iat' => $now,
            'exp' => $exp,
            'jti' => (string) Str::uuid(),

            // MediaMTX permissions claim
            'mediamtx_permissions' => [
                ['action' => 'publish', 'path' => $path],
            ],
        ];

        $jwt = JWT::encode($payload, $privateKey, 'RS256', $kid);

        return ['token' => $jwt, 'exp' => $exp];
    }
}

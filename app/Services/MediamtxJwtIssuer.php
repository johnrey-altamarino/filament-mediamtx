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

        ['kid' => $kid, 'iss' => $iss, 'aud' => $aud, 'privateKey' => $privateKey] = $this->jwtCommonConfig();

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

        if ($aud !== null) {
            $payload['aud'] = $aud;
        }

        $jwt = JWT::encode($payload, $privateKey, 'RS256', $kid);

        return [
            'id' => $payload['jti'],
            'token' => $jwt,
            'expires_at' => $exp,
            'expires_at_iso' => now()->setTimestamp($exp)->toIso8601String(),
        ];
    }

    public function issueReadToken(string $path): array
    {
        $now = time();
        $ttl = (int) config('services.mediamtx_jwt.ttl', 60);

        ['kid' => $kid, 'iss' => $iss, 'aud' => $aud, 'privateKey' => $privateKey] = $this->jwtCommonConfig();

        $payload = [
            'iss' => $iss,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => (string) Str::uuid(),
            'mediamtx_permissions' => [
                ['action' => 'read', 'path' => $path],
            ],
        ];

        if ($aud !== null) {
            $payload['aud'] = $aud;
        }

        $jwt = JWT::encode($payload, $privateKey, 'RS256', $kid);

        return [
            'id' => $payload['jti'],
            'token' => $jwt,
            'expires_at' => $payload['exp'],
            'expires_at_iso' => now()->setTimestamp($payload['exp'])->toIso8601String(),
        ];
    }

    private function jwtCommonConfig(): array
    {
        return [
            'kid' => (string) config('services.mediamtx_jwt.kid', 'mediamtx-1'),
            'iss' => (string) (config('services.mediamtx_jwt.iss') ?: config('app.url')),
            'aud' => ($aud = config('services.mediamtx_jwt.aud')) ? (string) $aud : null,
            'privateKey' => app('mediamtx.jwt.private_key'),
        ];
    }
}

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
        $exp = $noExpiry ? ($now + 60 * 60 * 24 * 365 * 10) : ($now + (int) env('JWT_TTL_SECONDS', 60));

        $kid = (string) env('JWT_KID', 'mediamtx-1');
        $iss = (string) env('JWT_ISSUER', config('app.url'));

        $privateKeyPath = (string) env('JWT_PRIVATE_KEY_PATH', 'storage/app/jwt/private.pem');
        $privateKey = @file_get_contents(base_path($privateKeyPath));

        if (! $privateKey) {
            throw new \RuntimeException('JWT private key not configured');
        }

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

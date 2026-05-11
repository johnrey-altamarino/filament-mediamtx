<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class JwksController extends Controller
{
    public function __invoke()
    {
        $kid = (string) config('services.mediamtx_jwt.kid', 'mediamtx-1');

        $publicKeyPath = (string) config('services.mediamtx_jwt.public_key_path', 'storage/app/jwt/public.pem');
        $pem = file_get_contents(base_path($publicKeyPath));

        if (! $pem) {
            return response()->json(['message' => 'JWT public key not configured'], 500);
        }

        $jwk = $this->pemToJwk($pem, $kid);

        return response()->json([
            'keys' => [$jwk],
        ]);
    }

    private function pemToJwk(string $pem, string $kid): array
    {
        $res = openssl_pkey_get_public($pem);
        if (! $res) {
            abort(500, 'Invalid public key');
        }

        $details = openssl_pkey_get_details($res);
        if (! isset($details['rsa']['n'], $details['rsa']['e'])) {
            abort(500, 'Public key is not RSA');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $this->base64url($details['rsa']['n']),
            'e' => $this->base64url($details['rsa']['e']),
        ];
    }

    private function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

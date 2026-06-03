<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'viewer_log' => [
        'throttle_seconds' => (int) env('VIEWER_LOG_THROTTLE_SECONDS', 10),
        'throttle_mode' => env('VIEWER_LOG_THROTTLE_MODE', 'ip_path_ua'),
    ],

    'mediamtx' => [
        'api_base_url' => env('MEDIAMTX_API_BASE_URL', 'http://127.0.0.1:9997'),
        'api_user' => env('MEDIAMTX_API_USER'),
        'api_pass' => env('MEDIAMTX_API_PASS'),
        'api_timeout' => (int) env('MEDIAMTX_API_TIMEOUT', 3),
        'allowed_ips' => env('MEDIAMTX_ALLOWED_IPS', ''),
        'stale_seconds' => (int) env('MEDIAMTX_SESSION_STALE_SECONDS', 20),
        'stream_base_url' => env('MEDIAMTX_STREAM_BASE_URL', 'https://stream.jrta.online'),
        'trusted_proxies' => env('MEDIAMTX_TRUSTED_PROXIES', ''),
    ],

    'mediamtx_jwt' => [
        'private_key_path' => env('JWT_PRIVATE_KEY_PATH', 'storage/app/jwt/private.pem'),
        'public_key_path' => env('JWT_PUBLIC_KEY_PATH', 'storage/app/jwt/public.pem'),
        'kid' => env('JWT_KID', 'mediamtx-1'),
        'iss' => env('JWT_ISSUER'),
        'ttl' => (int) env('JWT_TTL_SECONDS', 60),
    ],

];

<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => [env('APP_URL', 'http://localhost')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,
];

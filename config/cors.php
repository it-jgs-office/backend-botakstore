<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'FRONTEND_URL',
        'http://localhost:8080,http://127.0.0.1:8080,http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173',
    )))),
    'allowed_origins_patterns' => [
        '#^http://localhost(?::\d+)?$#',
        '#^http://127\.0\.0\.1(?::\d+)?$#',
        '#^http://\[::1\](?::\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];

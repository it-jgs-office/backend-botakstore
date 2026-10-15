<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'ttl' => (int) env('JWT_TTL', 120),
    'issuer' => env('APP_URL', 'http://localhost'),
    'cookie' => env('JWT_COOKIE', 'botakstore_session'),
    'cookie_secure' => (bool) env('JWT_COOKIE_SECURE', env('APP_ENV') === 'production'),
    'cookie_same_site' => env('JWT_COOKIE_SAME_SITE', 'strict'),
];

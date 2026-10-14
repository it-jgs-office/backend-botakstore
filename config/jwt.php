<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'ttl' => (int) env('JWT_TTL', 120),
    'issuer' => env('APP_URL', 'http://localhost'),
];

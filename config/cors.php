<?php

return [
    'paths' => ['api/*', 'storage/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('POS_ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1,http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173'))
    )),
    'allowed_origins_patterns' => array_filter(array_map(
        'trim',
        explode(',', (string) env('POS_ALLOWED_ORIGIN_PATTERNS', ''))
    )),
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-Outlet-Id',
        'X-POS-API-TOKEN',
        'X-USER-TOKEN',
        'X-Idempotency-Key',
        'X-Request-Id',
    ],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 86400,
    'supports_credentials' => false,
];

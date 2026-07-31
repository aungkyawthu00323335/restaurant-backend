<?php

$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env(
        'POS_ALLOWED_ORIGINS',
        'http://localhost,http://127.0.0.1,http://localhost:3000,http://localhost:8080',
    )),
)));

$originPatterns = array_values(array_filter(array_map(
    static fn (string $pattern): string => trim($pattern),
    explode(',', (string) env('POS_ALLOWED_ORIGIN_PATTERNS', '')),
)));

if (in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    $originPatterns[] = '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#';
}

return [
    'paths' => ['api/*', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => array_values(array_unique($originPatterns)),
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Outlet-Id',
        'X-POS-API-TOKEN',
        'X-Request-Id',
        'X-USER-TOKEN',
        'Idempotency-Key',
    ],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];

<?php

$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env(
        'POS_ALLOWED_ORIGINS',
        '*',
    )),
)));

$originPatterns = array_values(array_filter(array_map(
    static fn (string $pattern): string => trim($pattern),
    explode(',', (string) env('POS_ALLOWED_ORIGIN_PATTERNS', '')),
)));

return [
    'paths' => ['api/*', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => array_values(array_unique($originPatterns)),
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];

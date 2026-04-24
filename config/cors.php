<?php

declare(strict_types=1);

return [
    'paths' => ['v1/*', 'internal/*'],
    'allowed_methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'Accept'],
    'exposed_headers' => [
        'X-Gateway-Request-Id',
        'X-Gateway-Anthropic-Request-Id',
        'X-Gateway-Model-Alias',
        'X-Gateway-Model-Snapshot',
        'X-Gateway-Cost-USD',
        'X-Gateway-Cost-Breakdown',
        'X-Gateway-Spend-Remaining-USD',
        'X-Gateway-Estimated-Cost-USD',
        'X-Gateway-Service-Tier-Used',
        'X-Gateway-Cache-Hit-Tokens',
        'X-Gateway-Warning',
        'Retry-After',
    ],
    'max_age' => 600,
    'supports_credentials' => false,
];

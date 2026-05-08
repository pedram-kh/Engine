<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) configuration
    |--------------------------------------------------------------------------
    |
    | Per docs/05-SECURITY-COMPLIANCE.md §9.2, CORS is allowed only for the
    | first-party SPA origins. Wildcards are forbidden in production. Cookie
    | credentials must flow cross-origin between API and SPAs, so
    | `supports_credentials` is true.
    |
    | Allowed origins are read from FRONTEND_MAIN_URL and FRONTEND_ADMIN_URL.
    | Any extra origins for local dev tooling can go in FRONTEND_EXTRA_ORIGINS
    | as a comma-separated list.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_unique(array_merge(
        [
            (string) env('FRONTEND_MAIN_URL', 'http://127.0.0.1:5173'),
            (string) env('FRONTEND_ADMIN_URL', 'http://127.0.0.1:5174'),
        ],
        array_map('trim', explode(',', (string) env('FRONTEND_EXTRA_ORIGINS', ''))),
    )), static fn (string $value): bool => $value !== '')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Retry-After',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];

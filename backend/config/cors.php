<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The SPA authenticates with an HttpOnly session cookie, so credentials are
| mandatory. `allowed_origins` must therefore list explicit application
| origins; Laravel rejects a wildcard whenever credentials are enabled.
|
| Configure deployments with HRMS_CORS_ALLOWED_ORIGINS as a comma-separated
| list, for example "https://hrms.example.com,https://hrms-admin.example.com".
|
*/
$origins = (string) env(
    'HRMS_CORS_ALLOWED_ORIGINS',
    'http://localhost:5173,http://127.0.0.1:5173,http://localhost:5174,http://127.0.0.1:5174',
);

return [
    'paths' => ['v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', $origins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-CSRF-Token',
        'X-Legal-Entity-Id',
        'X-Request-Id',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'Idempotency-Replayed',
        'X-Request-Id',
    ],

    'max_age' => 600,

    'supports_credentials' => true,
];

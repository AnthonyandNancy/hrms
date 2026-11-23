<?php

declare(strict_types=1);

return [
    'default_timezone' => env('HRMS_DEFAULT_TIMEZONE', 'Asia/Shanghai'),

    /*
    |--------------------------------------------------------------------------
    | Person PII hashing
    |--------------------------------------------------------------------------
    |
    | HMAC key for identity dedupe hashes. Set HRMS_PII_HMAC_KEY in every
    | environment; tests override it with a deterministic synthetic value.
    |
    */
    'pii_hmac_key' => env('HRMS_PII_HMAC_KEY', 'reference-implementation-change-me'),

    /*
    |--------------------------------------------------------------------------
    | Public API metadata
    |--------------------------------------------------------------------------
    |
    | This is a deliberately small subset of IANA timezones exposed to clients.
    | New legal-entity timezones must be added here and documented before use.
    |
    */
    'supported_timezones' => [
        'UTC',
        'Asia/Shanghai',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
    ],
];

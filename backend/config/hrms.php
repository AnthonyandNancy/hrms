<?php

declare(strict_types=1);

return [
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

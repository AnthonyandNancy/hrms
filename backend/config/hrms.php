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
    'feature_flags' => [
        'payroll_enabled' => env('HRMS_FEATURE_PAYROLL', false),
        'time_enabled' => env('HRMS_FEATURE_TIME', false),
        'self_service_enabled' => env('HRMS_FEATURE_SELF_SERVICE', false),
        'recruitment_enabled' => env('HRMS_FEATURE_RECRUITMENT', false),
        'export_enabled' => env('HRMS_FEATURE_EXPORT', false),
    ],

    'supported_timezones' => [
        'UTC',
        'Asia/Shanghai',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
    ],
];

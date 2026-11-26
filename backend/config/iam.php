<?php

declare(strict_types=1);

return [
    'session_cookie' => 'hrms_session',

    'session' => [
        'cookie' => 'hrms_session',
        'path' => '/',
        'domain' => env('IAM_SESSION_DOMAIN'),
        'secure' => (bool) env('IAM_SESSION_SECURE', env('APP_ENV', 'production') === 'production'),
        'http_only' => true,
        'same_site' => 'lax',
        'standard_idle_minutes' => (int) env('IAM_SESSION_STANDARD_IDLE_MINUTES', 30),
        'standard_absolute_hours' => (int) env('IAM_SESSION_STANDARD_ABSOLUTE_HOURS', 12),
        'privileged_idle_minutes' => (int) env('IAM_SESSION_PRIVILEGED_IDLE_MINUTES', 15),
        'privileged_absolute_hours' => (int) env('IAM_SESSION_PRIVILEGED_ABSOLUTE_HOURS', 8),
    ],

    'login' => [
        'max_failures' => 10,
        'failure_window_minutes' => 15,
        // Transient per-username/IP login throttle. Automated end-to-end suites
        // may raise this in their own environment; the persistent lockout below
        // is independent and always applies.
        'rate_limit_per_minute' => (int) env('IAM_LOGIN_RATE_LIMIT_PER_MINUTE', 5),
    ],

    'mfa' => [
        // ADR-0038: username + password is the only required login flow.
        // MFA remains available as an opt-in defence-in-depth control; set
        // IAM_MFA_ENABLED=true to restore login challenges and step-up.
        'enabled' => (bool) env('IAM_MFA_ENABLED', false),
        'step_up_minutes' => 15,
        'totp_step_seconds' => 30,
        'totp_window' => 1,
        'totp_digits' => 6,
        'totp_issuer' => env('IAM_TOTP_ISSUER', 'HRMS'),
        'recovery_code_count' => 10,
        'webauthn_challenge_ttl_seconds' => 300,
        'webauthn_rp_id' => env('IAM_WEBAUTHN_RP_ID', 'localhost'),
        'webauthn_origin' => env('IAM_WEBAUTHN_ORIGIN', 'http://localhost'),
    ],

    'break_glass' => [
        'duration_minutes' => 30,
        'reason_min_length' => 10,
        'scope' => ['*'],
    ],

    'authorization' => [
        'binding_cache_seconds' => 60,
        'super_admin_max_hours' => 24,
    ],

    'demo_password_env' => 'DEMO_SEED_PASSWORD',
    'demo_seed_password' => env('DEMO_SEED_PASSWORD'),
];

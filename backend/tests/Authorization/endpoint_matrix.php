<?php

declare(strict_types=1);

/**
 * Authorization negative-test endpoint matrix.
 *
 * Keyed by "METHOD /v1/path". capability:audit warns when a guarded route is
 * missing an entry. Each entry records the denial cases exercised or planned.
 */

return [
    'GET /v1/me' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'low',
        'negative_tests' => ['unauthenticated', 'stale_auth_version', 'session_revoked'],
    ],
    'GET /v1/me/capabilities' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'low',
        'negative_tests' => ['unauthenticated', 'stale_auth_version', 'session_revoked'],
    ],
    'POST /v1/auth/logout' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'low',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'csrf_invalid'],
    ],
    'POST /v1/auth/logout-all' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'csrf_invalid'],
    ],
    'POST /v1/auth/password' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'wrong_current_password'],
    ],
    'GET /v1/users' => [
        'capability' => 'iam.user.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'stale_auth_version', 'field_denied'],
    ],
    'POST /v1/users' => [
        'capability' => 'iam.user.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'csrf_missing', 'stale_auth_version'],
    ],
    'PATCH /v1/users/{id}' => [
        'capability' => 'iam.user.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'idor', 'csrf_missing', 'stale_auth_version'],
    ],
    'POST /v1/users/{id}/roles' => [
        'capability' => 'iam.role.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'idor', 'sod_conflict', 'stale_auth_version'],
    ],
    'GET /v1/users/{id}/bindings' => [
        'capability' => 'iam.binding.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'idor', 'stale_auth_version'],
    ],
    'POST /v1/users/{id}/bindings' => [
        'capability' => 'iam.binding.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'idor', 'sod_conflict', 'invalid_anchor', 'field_profile_over_max', 'stale_auth_version'],
    ],
    'DELETE /v1/bindings/{id}' => [
        'capability' => 'iam.binding.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'idor', 'stale_auth_version'],
    ],
    'GET /v1/roles' => [
        'capability' => 'iam.role.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'stale_auth_version'],
    ],
    'GET /v1/capabilities' => [
        'capability' => 'iam.role.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity'],
    ],
    'GET /v1/auth/events' => [
        'capability' => 'iam.audit.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'mfa_required', 'cross_legal_entity', 'stale_auth_version', 'field_denied'],
    ],
    'POST /v1/auth/mfa/totp/enroll' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'mfa_factor_exists'],
    ],
    'POST /v1/auth/mfa/totp/confirm' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'mfa_code_invalid', 'mfa_factor_not_found'],
    ],
    'POST /v1/auth/mfa/step-up' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'csrf_missing', 'mfa_code_invalid', 'sms_email_forbidden', 'mfa_factor_not_found'],
    ],
    'POST /v1/auth/webauthn/register/options' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'csrf_missing'],
    ],
    'POST /v1/auth/webauthn/assert/options' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'csrf_missing'],
    ],
    'POST /v1/auth/break-glass' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'same_person_approver', 'reason_too_short', 'approver_missing', 'mfa_required', 'csrf_missing'],
    ],
    'POST /v1/auth/break-glass/{id}/revoke' => [
        'capability' => 'iam.session.manage',
        'anchor' => 'SELF',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'not_party_to_grant', 'grant_missing', 'csrf_missing'],
    ],
    'GET /v1/health' => ['public' => true, 'negative_tests' => []],
    'GET /v1/meta' => ['public' => true, 'negative_tests' => []],
    'GET /v1/health/live' => ['public' => true, 'negative_tests' => []],
    'GET /v1/health/ready' => ['public' => true, 'negative_tests' => []],
];

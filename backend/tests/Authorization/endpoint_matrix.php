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
    'POST /v1/lifecycle/onboarding' => [
        'capability' => 'lifecycle.onboarding.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/probation-confirmations' => [
        'capability' => 'lifecycle.probation.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/transfers' => [
        'capability' => 'lifecycle.transfer.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/salary-changes' => [
        'capability' => 'lifecycle.terms.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/offboardings' => [
        'capability' => 'lifecycle.offboard.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'critical',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/contract-renewals' => [
        'capability' => 'lifecycle.contract.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/lifecycle/rehires' => [
        'capability' => 'lifecycle.rehire.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/lifecycle/cases/{caseId}' => [
        'capability' => 'lifecycle.case.read',
        'anchor' => 'CASE',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/employees/{employment_id}/onboarding-checklist' => [
        'capability' => 'lifecycle.case.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'PATCH /v1/onboarding-tasks/{id}' => [
        'capability' => 'lifecycle.checklist.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/recruitment/requisitions' => [
        'capability' => 'recruitment.requisition.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/recruitment/requisitions' => [
        'capability' => 'recruitment.requisition.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/recruitment/candidates' => [
        'capability' => 'recruitment.candidate.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/recruitment/candidates' => [
        'capability' => 'recruitment.candidate.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/recruitment/candidates/{id}/interviews' => [
        'capability' => 'recruitment.interview.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/recruitment/offers' => [
        'capability' => 'recruitment.offer.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/recruitment/offers/{id}/accept' => [
        'capability' => 'recruitment.offer.accept',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/performance/cycles' => [
        'capability' => 'performance.cycle.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/performance/cycles' => [
        'capability' => 'performance.cycle.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/performance/reviews' => [
        'capability' => 'performance.review.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/performance/reviews/{id}/submit' => [
        'capability' => 'performance.review.submit',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'high',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/performance/reviews/{id}/appeals' => [
        'capability' => 'performance.appeal.manage',
        'anchor' => 'SELF',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/training/programs' => [
        'capability' => 'training.program.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'low',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/training/programs' => [
        'capability' => 'training.program.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/training/sessions' => [
        'capability' => 'training.session.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/training/enrollments' => [
        'capability' => 'training.enrollment.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'POST /v1/training/enrollments/{id}/complete' => [
        'capability' => 'training.completion.manage',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
    'GET /v1/training/report' => [
        'capability' => 'training.report.read',
        'anchor' => 'LEGAL_ENTITY',
        'risk' => 'medium',
        'negative_tests' => ['unauthenticated', 'no_capability', 'cross_legal_entity', 'idempotency_missing', 'validation'],
    ],
];

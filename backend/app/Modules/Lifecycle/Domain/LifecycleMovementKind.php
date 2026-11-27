<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

/**
 * Maps the stable lifecycle case types to the movement kinds rendered by the
 * unified ledger. The mapping is owned by Lifecycle; callers must not infer a
 * kind from a translated label or from a workflow title.
 */
final class LifecycleMovementKind
{
    /** @var list<string> */
    public const LEDGER_CASE_TYPES = [
        LifecycleCaseType::ONBOARDING->value,
        LifecycleCaseType::PROBATION_CONFIRM->value,
        LifecycleCaseType::ASSIGNMENT_CHANGE->value,
        LifecycleCaseType::TERMS_CHANGE->value,
        LifecycleCaseType::OFFBOARD->value,
    ];

    /** @var array<string, string> */
    private const KIND_TO_CASE_TYPE = [
        'onboarding' => LifecycleCaseType::ONBOARDING->value,
        'probation' => LifecycleCaseType::PROBATION_CONFIRM->value,
        'transfer' => LifecycleCaseType::ASSIGNMENT_CHANGE->value,
        'salary' => LifecycleCaseType::TERMS_CHANGE->value,
        'offboarding' => LifecycleCaseType::OFFBOARD->value,
        'contract_renew' => LifecycleCaseType::CONTRACT_RENEW->value,
        'rehire' => LifecycleCaseType::REHIRE->value,
    ];

    /** @var array<string, string> */
    private const CASE_TYPE_TO_KIND = [
        LifecycleCaseType::ONBOARDING->value => 'onboarding',
        LifecycleCaseType::PROBATION_CONFIRM->value => 'probation',
        LifecycleCaseType::ASSIGNMENT_CHANGE->value => 'transfer',
        LifecycleCaseType::TERMS_CHANGE->value => 'salary',
        LifecycleCaseType::OFFBOARD->value => 'offboarding',
        LifecycleCaseType::CONTRACT_RENEW->value => 'contract_renew',
        LifecycleCaseType::REHIRE->value => 'rehire',
    ];

    public static function forCaseType(?string $caseType): ?string
    {
        if ($caseType === null || $caseType === '') {
            return null;
        }

        return self::CASE_TYPE_TO_KIND[strtoupper($caseType)] ?? null;
    }

    public static function caseTypeForFilter(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (isset(self::KIND_TO_CASE_TYPE[$normalized])) {
            return self::KIND_TO_CASE_TYPE[$normalized];
        }

        $upper = strtoupper(trim($value));

        return in_array($upper, array_values(self::KIND_TO_CASE_TYPE), true) ? $upper : null;
    }
}

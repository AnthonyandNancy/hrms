<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\OnboardingCompensationDraftWriter;

/**
 * Payroll-side adapter over the canonical compensation version writer. The
 * draft remains DRAFT until Payroll explicitly activates it.
 */
final class OnboardingCompensationDraftService implements OnboardingCompensationDraftWriter
{
    public function __construct(private readonly CompensationService $compensations) {}

    public function createDraft(
        int $actorUserId,
        int $employmentId,
        int $caseId,
        int $contractVersionId,
        string $hireDate,
        array $terms,
    ): array {
        $effectiveFrom = (string) ($terms['effective_from'] ?? $hireDate);
        $compensation = $this->compensations->createCompensation(
            $actorUserId,
            [
                'employment_id' => $employmentId,
                'contract_version_id' => $contractVersionId,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'status' => 'DRAFT',
                'base_salary_cents' => (int) $terms['base_salary_minor'],
                'currency' => strtoupper((string) ($terms['salary_currency'] ?? 'CNY')),
                'salary_scheme_code' => $terms['salary_scheme_code'] ?? null,
                'approved_case_id' => $caseId,
                'source_type' => 'ONBOARDING',
                'source_id' => $caseId,
            ],
            $caseId,
        );

        return [
            'compensation_id' => (int) $compensation->id,
            'status' => (string) $compensation->status,
            'effective_from' => (string) $compensation->effective_from,
            'contract_version_id' => $compensation->contract_version_id !== null ? (int) $compensation->contract_version_id : null,
        ];
    }
}

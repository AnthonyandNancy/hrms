<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Payroll-owned write port for the initial execution-compensation draft created
 * by an approved onboarding effect. The caller must pass the frozen People
 * contract-version id; Payroll revalidates employment/date/currency.
 */
interface OnboardingCompensationDraftWriter
{
    /**
     * @param  array<string, mixed>  $terms  Frozen onboarding terms payload.
     * @return array{compensation_id:int, status:string, effective_from:string, contract_version_id:int|null}
     */
    public function createDraft(
        int $actorUserId,
        int $employmentId,
        int $caseId,
        int $contractVersionId,
        string $hireDate,
        array $terms,
    ): array;
}

<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * People-owned write port used by the approved onboarding effect. It creates
 * the initial draft contract and its immutable CREATION version in the same
 * transaction as the employment write.
 */
interface OnboardingContractDraftWriter
{
    /**
     * @param  array<string, mixed>  $terms  Frozen onboarding terms payload.
     * @return array{contract_id:int, contract_version_id:int, contract_no:string, status:string}
     */
    public function createDraft(
        int $employmentId,
        int $caseId,
        string $employeeNo,
        string $employmentType,
        string $hireDate,
        array $terms,
        int $actorUserId,
    ): array;
}

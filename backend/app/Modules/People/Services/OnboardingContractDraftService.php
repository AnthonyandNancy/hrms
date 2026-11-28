<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\People\Contracts\OnboardingContractDraftWriter;
use App\Modules\People\Models\EmploymentContract;
use App\Shared\Time\BusinessDate;

/**
 * Sole People-side composer for the onboarding contract draft. It reuses the
 * existing contract writer and version ledger; no second contract table writer
 * is introduced.
 */
final class OnboardingContractDraftService implements OnboardingContractDraftWriter
{
    public function __construct(
        private readonly EmploymentContractService $contracts,
        private readonly EmploymentContractVersionLedger $versions,
    ) {}

    public function createDraft(
        int $employmentId,
        int $caseId,
        string $employeeNo,
        string $employmentType,
        string $hireDate,
        array $terms,
        int $actorUserId,
    ): array {
        $startDate = (string) ($terms['contract_start_date'] ?? $terms['effective_from'] ?? $hireDate);
        $endDate = isset($terms['contract_end_date']) && $terms['contract_end_date'] !== ''
            ? (string) $terms['contract_end_date']
            : null;
        $contractType = $this->contractType($employmentType);
        $sequence = EmploymentContract::query()->where('employment_id', $employmentId)->count() + 1;
        $contractNo = mb_substr($employeeNo, 0, 72).'-C'.$sequence;
        $versionId = 0;

        $contract = $this->contracts->open($employmentId, [
            'contract_no' => $contractNo,
            'contract_type' => $contractType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'signed_on' => $terms['signed_at'] ?? null,
            'status' => 'DRAFT',
            'terms_summary' => 'Created from onboarding case '.$caseId,
            'work_location' => $terms['work_location'] ?? null,
            'probation_end_date' => $terms['probation_end_date'] ?? null,
            'source_type' => 'ONBOARDING',
            'source_id' => $caseId,
        ], $actorUserId, function (array $contract) use (&$versionId, $employmentId, $actorUserId, $caseId, $terms): void {
            $version = $this->versions->append(
                contractId: (int) $contract['id'],
                employmentId: $employmentId,
                changeType: 'CREATION',
                effectiveFrom: (string) $contract['start_date'],
                effectiveTo: (string) ($contract['end_date'] ?? BusinessDate::OPEN_END),
                reason: 'Onboarding case '.$caseId,
                snapshot: [
                    'contract_no' => (string) $contract['contract_no'],
                    'contract_type' => (string) $contract['contract_type'],
                    'start_date' => (string) $contract['start_date'],
                    'end_date' => $contract['end_date'],
                    'signed_on' => $contract['signed_on'],
                    'status' => (string) $contract['status'],
                    'probation_end_date' => $terms['probation_end_date'] ?? null,
                    'work_location' => $terms['work_location'] ?? null,
                    'terms_summary' => (string) ($contract['terms_summary'] ?? ''),
                    'agreed_salary_cents' => isset($terms['base_salary_minor']) ? (int) $terms['base_salary_minor'] : null,
                    'agreed_currency' => isset($terms['salary_currency']) ? strtoupper((string) $terms['salary_currency']) : null,
                ],
                actorUserId: $actorUserId > 0 ? $actorUserId : null,
            );
            $versionId = (int) $version['id'];
        });

        return [
            'contract_id' => (int) $contract['id'],
            'contract_version_id' => $versionId,
            'contract_no' => (string) $contract['contract_no'],
            'status' => (string) $contract['status'],
        ];
    }

    private function contractType(string $employmentType): string
    {
        return match (strtoupper($employmentType)) {
            'FIXED_TERM' => 'FIXED_TERM',
            'INTERN' => 'INTERNSHIP',
            'CONTRACTOR' => 'CONSULTANT',
            default => 'OPEN_ENDED',
        };
    }
}

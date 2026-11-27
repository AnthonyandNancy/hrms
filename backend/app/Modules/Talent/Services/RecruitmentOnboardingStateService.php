<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\Talent\Models\Offer;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;

/**
 * Reads onboarding status through Workflow and People contracts only. The
 * onboarding case is Lifecycle-owned, so Talent never opens lifecycle tables;
 * the stable case id is stored on the offer when the draft is created.
 */
final class RecruitmentOnboardingStateService
{
    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly WorkforceReportingDirectory $workforce,
    ) {}

    /**
     * @return array{case_id: int|null, status: string, employment_id: int|null, person_id: int|null}
     */
    public function onboardingStateForOffer(int $offerId, string $hireDate): array
    {
        $offer = Offer::query()->whereKey($offerId)->first();
        $caseId = $offer?->onboarding_case_id !== null ? (int) $offer->onboarding_case_id : null;

        if ($caseId === null) {
            return [
                'case_id' => null,
                'status' => 'NOT_CREATED',
                'application_status' => null,
                'effect_status' => null,
                'employment_id' => null,
                'person_id' => null,
            ];
        }

        $case = $this->workflow->caseFor($caseId);

        if ($case === null) {
            return [
                'case_id' => $caseId,
                'status' => 'NOT_FOUND',
                'application_status' => null,
                'effect_status' => null,
                'employment_id' => null,
                'person_id' => null,
            ];
        }

        $status = match (true) {
            $case->effectStatus === 'APPLIED' => 'APPLIED',
            in_array($case->effectStatus, ['FAILED', 'PARTIAL'], true) => $case->effectStatus,
            $case->applicationStatus === 'APPROVED' => 'APPROVED',
            in_array($case->applicationStatus, ['SUBMITTED', 'IN_REVIEW'], true) => 'SUBMITTED',
            in_array($case->applicationStatus, ['REJECTED', 'WITHDRAWN', 'CANCELLED'], true) => 'CANCELLED',
            default => 'DRAFT',
        };

        $employmentId = null;
        $personId = null;

        if ($status === 'APPLIED') {
            $employeeNo = (string) ($case->payload['employee_no'] ?? '');

            foreach ($this->workforce->rows($hireDate) as $row) {
                if ($employeeNo !== '' && $row->employeeNo === $employeeNo) {
                    $employmentId = $row->employmentId;
                    $personId = $row->personId;
                    break;
                }
            }
        }

        return [
            'case_id' => $caseId,
            'status' => $status,
            'application_status' => $case->applicationStatus,
            'effect_status' => $case->effectStatus,
            'employment_id' => $employmentId,
            'person_id' => $personId,
        ];
    }
}

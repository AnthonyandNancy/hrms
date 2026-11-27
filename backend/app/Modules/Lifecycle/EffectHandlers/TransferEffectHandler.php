<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\EffectHandlers;

use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\LifecycleEffectSupport;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;

/**
 * Assignment transfer between organization units inside the single company.
 * The employment is the stable subject; its assignment history is
 * effective-dated and the effect is idempotent through the lifecycle intent.
 */
final class TransferEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly OrganizationFacts $organization,
    ) {}

    public function supports(string $effectType): bool
    {
        return $effectType === LifecycleCaseType::ASSIGNMENT_CHANGE->effectType();
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.assignment.changed.v1', [
                    'employment_id' => (int) $existing->employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $employmentId = (int) ($payload['employment_id'] ?? 0);
            $effectiveDate = (string) ($payload['effective_date'] ?? '');
            $employment = $this->employments->employment($employmentId);

            if ($employment === null || BusinessDate::tryFrom($effectiveDate) === null) {
                throw new ValidationException(['employment_id' => ['A valid employment and effective date are required.']]);
            }

            if ($employment->status === 'TERMINATED') {
                throw new ConflictException('Cannot transfer a terminated employment.', ['reason' => 'EMPLOYMENT_TERMINATED']);
            }

            $effective = BusinessDate::from($effectiveDate);

            if (! $effective->isAfter(BusinessDate::from($employment->validFrom))) {
                throw new ValidationException(['effective_date' => ['The transfer must start after the employment start.']]);
            }

            $org = $this->organization->orgUnit((int) ($payload['org_unit_id'] ?? 0));

            if ($org === null) {
                throw new ValidationException(['org_unit_id' => ['The target organization unit does not exist.']]);
            }

            $current = $this->employments->assignmentAsOf($employmentId, $effective->addDays(-1)->value());
            $this->support->freezeBefore($command->caseId, [
                'employment_id' => $employmentId,
                'assignment' => $current !== null ? [
                    'assignment_id' => $current->id,
                    'org_unit_id' => $current->orgUnitId,
                    'position_id' => $current->positionId,
                    'assignment_type' => $current->assignmentType,
                    'is_primary' => $current->isPrimary,
                    'valid_from' => $current->validFrom,
                    'valid_to' => $current->validTo,
                ] : null,
                'employment_status' => $employment->status,
            ]);

            if ($current !== null) {
                $this->people->endAssignment($current->id, (string) $effectiveDate, null, 'TRANSFER', $command->caseId);
            }

            $newAssignmentId = $this->people->createAssignment($employmentId, [
                'org_unit_id' => $org->id,
                'position_id' => $payload['position_id'] ?? null,
                'manager_assignment_id' => $payload['manager_assignment_id'] ?? null,
                'assignment_type' => 'PRIMARY',
                'is_primary' => true,
                'valid_from' => $effectiveDate,
                'valid_to' => BusinessDate::OPEN_END,
                'source_type' => 'TRANSFER',
                'source_id' => $command->caseId,
            ], null);

            if (isset($payload['base_salary_minor']) || isset($payload['salary_scheme_code'])) {
                $asOf = $this->employments->termsAsOf($employmentId, $effective->addDays(-1)->value());

                if ($asOf === null) {
                    throw new ConflictException('Cannot append terms without a current terms version.', ['reason' => 'TERMS_VERSION_MISSING']);
                }

                $this->people->appendTerms($employmentId, [
                    'effective_from' => $effectiveDate,
                    'work_location' => $asOf->workLocation,
                    'work_schedule' => $asOf->workSchedule,
                    'salary_scheme_code' => $payload['salary_scheme_code'] ?? $asOf->salarySchemeCode,
                    'base_salary_minor' => $payload['base_salary_minor'] ?? $asOf->baseSalaryMinor,
                    'salary_currency' => $asOf->salaryCurrency,
                    'notice_period_days' => $asOf->noticePeriodDays,
                    'confidentiality' => $asOf->confidentiality,
                    'non_compete' => $asOf->nonCompete,
                    'source_type' => 'TRANSFER',
                    'source_id' => $command->caseId,
                ], null);
            }

            $this->support->markEffective(
                caseId: $command->caseId,
                employmentId: $employmentId,
                resultEmploymentId: $employmentId,
                effectiveAt: $effectiveDate,
                after: [
                    'employment_id' => $employmentId,
                    'assignment_id' => $newAssignmentId,
                    'org_unit_id' => $org->id,
                    'position_id' => $payload['position_id'] ?? null,
                    'valid_from' => $effectiveDate,
                ],
                result: [
                    'employment_id' => $employmentId,
                    'assignment_id' => $newAssignmentId,
                    'effective_date' => $effectiveDate,
                ],
            );

            return DomainEffectResult::appliedWithOutbox('employment.assignment.changed.v1', [
                'employment_id' => $employmentId,
                'case_id' => $command->caseId,
                'effective_date' => $effectiveDate,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Assignment changes cannot be automatically reversed.');
    }
}

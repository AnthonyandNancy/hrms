<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\EffectHandlers;

use App\Modules\IAM\Contracts\UserDirectory;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\LifecycleEffectSupport;
use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;

final class OnboardingEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly OrganizationFacts $organization,
        private readonly CompanyProfileReader $company,
        private readonly UserDirectory $users,
    ) {}

    public function supports(string $effectType): bool
    {
        return in_array($effectType, [LifecycleCaseType::ONBOARDING->effectType(), LifecycleCaseType::REHIRE->effectType()], true);
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.created.v1', [
                    'employment_id' => (int) $existing->result_employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $this->assertPayload($payload, $command->effectType === LifecycleCaseType::REHIRE->effectType());

            $personId = $this->resolvePerson($payload);
            $org = $this->organization->orgUnit((int) $payload['org_unit_id']);

            if ($org === null) {
                throw new ValidationException(['org_unit_id' => ['The org unit does not exist.']]);
            }

            $hireDate = (string) $payload['hire_date'];
            $seniorityStart = (string) ($payload['seniority_start_date'] ?? $hireDate);

            if (($payload['seniority_rule'] ?? null) === 'PRESERVE_ORIGINAL' && isset($payload['previous_employment_id'])) {
                $previous = $this->employments->employment((int) $payload['previous_employment_id']);

                if ($previous !== null) {
                    $seniorityStart = $previous->seniorityStartDate;
                }
            }

            $this->support->freezeBefore($command->caseId, [
                'person_id' => $personId,
                'previous_employment_id' => $payload['previous_employment_id'] ?? null,
                'employment_id' => null,
            ]);

            $employmentId = $this->people->createEmployment([
                'person_id' => $personId,
                'employee_no' => (string) $payload['employee_no'],
                'employment_type' => (string) $payload['employment_type'],
                'status' => (string) ($payload['initial_status'] ?? 'PROBATION'),
                'valid_from' => $hireDate,
                'valid_to' => BusinessDate::OPEN_END,
                'hire_date' => $hireDate,
                'seniority_start_date' => $seniorityStart,
                'source_type' => $command->effectType === LifecycleCaseType::REHIRE->effectType() ? 'REHIRE' : 'ONBOARDING',
                'source_id' => $command->caseId,
            ], null);

            $assignmentId = $this->people->createAssignment($employmentId, [
                'org_unit_id' => (int) $payload['org_unit_id'],
                'position_id' => $payload['position_id'] ?? null,
                'manager_assignment_id' => $payload['manager_assignment_id'] ?? null,
                'assignment_type' => 'PRIMARY',
                'is_primary' => true,
                'valid_from' => $hireDate,
                'valid_to' => BusinessDate::OPEN_END,
                'source_type' => 'CASE',
                'source_id' => $command->caseId,
            ], null);

            $terms = (array) ($payload['terms'] ?? []);
            $probationEnd = $terms['probation_end_date'] ?? null;
            $this->people->appendTerms($employmentId, [
                'effective_from' => $hireDate,
                'probation_end_date' => $probationEnd,
                'work_location' => (string) ($terms['work_location'] ?? 'Unspecified'),
                'work_schedule' => (string) ($terms['work_schedule'] ?? 'STANDARD'),
                'salary_scheme_code' => $terms['salary_scheme_code'] ?? null,
                'base_salary_minor' => $terms['base_salary_minor'] ?? null,
                'salary_currency' => (string) ($terms['salary_currency'] ?? $this->company->current()->currencyCode),
                'notice_period_days' => (int) ($terms['notice_period_days'] ?? 0),
                'confidentiality' => (bool) ($terms['confidentiality'] ?? false),
                'non_compete' => (bool) ($terms['non_compete'] ?? false),
                'signed_at' => $terms['signed_at'] ?? null,
                'source_type' => 'CASE',
                'source_id' => $command->caseId,
            ], null);

            $this->linkUserIfPresent($command, $payload, $personId, $employmentId);
            $this->support->createOnboardingChecklist($command->caseId, $employmentId, $hireDate);
            $this->support->markEffective(
                caseId: $command->caseId,
                employmentId: $employmentId,
                resultEmploymentId: $employmentId,
                effectiveAt: $hireDate,
                after: [
                    'employment_id' => $employmentId,
                    'person_id' => $personId,
                    'assignment_id' => $assignmentId,
                    'org_unit_id' => (int) $payload['org_unit_id'],
                    'hire_date' => $hireDate,
                ],
                result: [
                    'employment_id' => $employmentId,
                    'person_id' => $personId,
                    'assignment_id' => $assignmentId,
                    'hire_date' => $hireDate,
                ],
            );

            return DomainEffectResult::appliedWithOutbox('employment.created.v1', [
                'employment_id' => $employmentId,
                'person_id' => $personId,
                'assignment_id' => $assignmentId,
                'case_id' => $command->caseId,
            ]);
        });
    }

    public function reverse(DomainEffectCommand $command): DomainEffectResult
    {
        throw new \RuntimeException('Onboarding effects cannot be automatically reversed.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePerson(array $payload): int
    {
        if (isset($payload['person_id']) && (int) $payload['person_id'] > 0) {
            $personId = (int) $payload['person_id'];

            if ($this->employments->hasOverlappingEmployment(
                $personId,
                (string) $payload['hire_date'],
                BusinessDate::OPEN_END,
            )) {
                throw (new ValidationException(['person_id' => ['The person already has an overlapping active employment.']]))
                    ->withReason('OVERLAPPING_ACTIVE_EMPLOYMENT');
            }

            return $personId;
        }

        $person = (array) ($payload['person'] ?? []);

        if ($person === []) {
            throw new ValidationException(['person' => ['Either person_id or a person object is required.']]);
        }

        return $this->people->createPerson($person, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkUserIfPresent(DomainEffectCommand $command, array $payload, int $personId, int $employmentId): void
    {
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : $this->users->userIdForPerson($personId);

        if ($userId === null) {
            return;
        }

        $this->people->linkUser(
            userId: $userId,
            employmentId: $employmentId,
            validFrom: (string) ($payload['hire_date'] ?? BusinessDate::OPEN_END),
            validTo: null,
            actorUserId: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayload(array $payload, bool $rehire): void
    {
        foreach (['employee_no', 'org_unit_id', 'hire_date', 'employment_type'] as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '') {
                throw new ValidationException([$field => ["The {$field} field is required."]]);
            }
        }

        if ($this->employments->employeeNumberExists((string) $payload['employee_no'])) {
            throw (new ConflictException('Employee number must be unique.', ['reason' => 'EMPLOYEE_NO_DUPLICATE']));
        }

        if ($rehire && ! isset($payload['previous_employment_id'])) {
            throw new ValidationException(['previous_employment_id' => ['A rehire needs the previous employment for seniority policy.']]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\EffectHandlers;

use App\Modules\IAM\Contracts\UserDirectory;
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

final class OnboardingEffectHandler implements DomainEffectHandler
{
    public function __construct(
        private readonly LifecycleEffectSupport $support,
        private readonly PeopleEmploymentWriter $people,
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly OrganizationFacts $organization,
        private readonly UserDirectory $users,
    ) {}

    public function supports(string $effectType): bool
    {
        return in_array($effectType, [LifecycleCaseType::ONBOARDING->effectType(), LifecycleCaseType::REHIRE->effectType()], true);
    }

    public function apply(DomainEffectCommand $command): DomainEffectResult
    {
        return $this->support->transactional(function () use ($command): DomainEffectResult {
            $existing = $this->support->alreadyApplied($command->tenantId, $command->caseId);

            if ($existing !== null) {
                return DomainEffectResult::appliedWithOutbox('employment.created.v1', [
                    'employment_id' => (int) $existing->result_employment_id,
                    'case_id' => $command->caseId,
                ]);
            }

            $payload = $this->support->payload($command);
            $this->assertPayload($command->tenantId, $payload, $command->effectType === LifecycleCaseType::REHIRE->effectType());

            $personId = $this->resolvePerson($command->tenantId, $payload);
            $entity = $this->organization->legalEntity($command->tenantId, (int) $payload['legal_entity_id']);
            $org = $this->organization->orgUnit($command->tenantId, (int) $payload['org_unit_id']);

            if ($entity === null || $org === null || $org->legalEntityId !== $entity->id) {
                throw new ValidationException(['org_unit_id' => ['The org unit must belong to the employment legal entity.']]);
            }

            $hireDate = (string) $payload['hire_date'];
            $seniorityStart = (string) ($payload['seniority_start_date'] ?? $hireDate);

            if (($payload['seniority_rule'] ?? null) === 'PRESERVE_ORIGINAL' && isset($payload['previous_employment_id'])) {
                $previous = $this->employments->employment($command->tenantId, (int) $payload['previous_employment_id']);

                if ($previous !== null) {
                    $seniorityStart = $previous->seniorityStartDate;
                }
            }

            $employmentId = $this->people->createEmployment($command->tenantId, [
                'person_id' => $personId,
                'legal_entity_id' => (int) $payload['legal_entity_id'],
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

            $assignmentId = $this->people->createAssignment($command->tenantId, $employmentId, [
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
            $this->people->appendTerms($command->tenantId, $employmentId, [
                'effective_from' => $hireDate,
                'probation_end_date' => $probationEnd,
                'work_location' => (string) ($terms['work_location'] ?? 'Unspecified'),
                'work_schedule' => (string) ($terms['work_schedule'] ?? 'STANDARD'),
                'salary_scheme_code' => $terms['salary_scheme_code'] ?? null,
                'base_salary_minor' => $terms['base_salary_minor'] ?? null,
                'salary_currency' => (string) ($terms['salary_currency'] ?? $entity->currencyCode),
                'notice_period_days' => (int) ($terms['notice_period_days'] ?? 0),
                'confidentiality' => (bool) ($terms['confidentiality'] ?? false),
                'non_compete' => (bool) ($terms['non_compete'] ?? false),
                'signed_at' => $terms['signed_at'] ?? null,
                'source_type' => 'CASE',
                'source_id' => $command->caseId,
            ], null);

            $this->linkUserIfPresent($command, $payload, $personId, $employmentId);
            $this->support->createOnboardingChecklist($command->tenantId, $command->caseId, $employmentId, $hireDate);
            $this->support->markEffective($command->tenantId, $command->caseId, $employmentId, $employmentId, $hireDate);

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
    private function resolvePerson(int $tenantId, array $payload): int
    {
        if (isset($payload['person_id']) && (int) $payload['person_id'] > 0) {
            $personId = (int) $payload['person_id'];

            if ($this->employments->hasOverlappingEmployment(
                $tenantId,
                $personId,
                (int) $payload['legal_entity_id'],
                (string) $payload['hire_date'],
                BusinessDate::OPEN_END,
            )) {
                throw (new ValidationException(['person_id' => ['The person already has an overlapping active employment in this legal entity.']]))
                    ->withReason('OVERLAPPING_ACTIVE_EMPLOYMENT');
            }

            return $personId;
        }

        $person = (array) ($payload['person'] ?? []);

        if ($person === []) {
            throw new ValidationException(['person' => ['Either person_id or a person object is required.']]);
        }

        return $this->people->createPerson($tenantId, $person, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkUserIfPresent(DomainEffectCommand $command, array $payload, int $personId, int $employmentId): void
    {
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : $this->users->userIdForPerson($command->tenantId, $personId);

        if ($userId === null) {
            return;
        }

        $this->people->linkUser(
            tenantId: $command->tenantId,
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
    private function assertPayload(int $tenantId, array $payload, bool $rehire): void
    {
        foreach (['legal_entity_id', 'employee_no', 'org_unit_id', 'hire_date', 'employment_type'] as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '') {
                throw new ValidationException([$field => ["The {$field} field is required."]]);
            }
        }

        if ($this->employments->employeeNumberExists($tenantId, (int) $payload['legal_entity_id'], (string) $payload['employee_no'])) {
            throw (new ConflictException('Employee number must be unique per tenant and legal entity.', ['reason' => 'EMPLOYEE_NO_DUPLICATE']));
        }

        if ($rehire && ! isset($payload['previous_employment_id'])) {
            throw new ValidationException(['previous_employment_id' => ['A rehire needs the previous employment for seniority policy.']]);
        }
    }
}

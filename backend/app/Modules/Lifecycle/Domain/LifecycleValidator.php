<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;

/**
 * Deterministic pre-submit validation. It uses only contracts; the same rules
 * are re-checked by effect handlers (defense in depth under a stale approval).
 */
final class LifecycleValidator
{
    public function __construct(
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly OrganizationFacts $organization,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertOnboarding(array $payload): void
    {
        $org = $this->organization->orgUnit((int) $payload['org_unit_id']);

        if ($org === null) {
            throw new ValidationException(['org_unit_id' => ['The organization unit does not exist.']]);
        }

        if ($this->employments->employeeNumberExists((string) $payload['employee_no'])) {
            throw (new ValidationException(['employee_no' => ['Employee number already exists.']]))
                ->withReason('EMPLOYEE_NO_DUPLICATE');
        }

        $candidatePersonId = isset($payload['person_id']) ? (int) $payload['person_id'] : null;

        if ($candidatePersonId !== null && $this->employments->hasOverlappingEmployment(
            $candidatePersonId,
            (string) $payload['hire_date'],
            BusinessDate::OPEN_END,
        )) {
            throw (new ValidationException(['person_id' => ['The person already has an overlapping active employment.']]))
                ->withReason('OVERLAPPING_ACTIVE_EMPLOYMENT');
        }

        if (isset($payload['manager_assignment_id'])
            && ! $this->employments->activeManagerAssignment((int) $payload['manager_assignment_id'], (string) $payload['hire_date'])) {
            throw (new ValidationException(['manager_assignment_id' => ['The manager assignment must be active on the hire date.']]))
                ->withReason('MANAGER_NOT_ACTIVE');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertEmployment(array $payload): void
    {
        if ($this->employments->employment((int) $payload['employment_id']) === null) {
            throw (new ValidationException(['employment_id' => ['The employment does not exist.']]))
                ->withReason('EMPLOYMENT_NOT_FOUND');
        }
    }
}

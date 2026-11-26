<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Effective-dated People facts needed by Lifecycle validation and read models.
 * All methods use half-open business-date ranges.
 */
interface EmploymentLifecycleDirectory
{
    /**
     * @return list<EmploymentLifecycleSnapshot>
     */
    public function employmentsForPerson(int $personId): array;

    public function employment(int $employmentId, ?string $knownAt = null): ?EmploymentLifecycleSnapshot;

    /**
     * Whether another non-terminated employment for the same person overlaps the
     * supplied half-open range.
     */
    public function hasOverlappingEmployment(int $personId, string $validFrom, string $validTo): bool;

    public function employeeNumberExists(string $employeeNo, ?int $exceptEmploymentId = null): bool;

    public function activeManagerAssignment(int $managerAssignmentId, string $on): bool;

    public function primaryAssignmentId(int $employmentId, string $on): ?int;

    public function isAssignmentActive(int $assignmentId, string $on): bool;

    /**
     * @return list<EmploymentTermsSnapshot>
     */
    public function termsVersions(int $employmentId): array;

    public function termsAsOf(int $employmentId, string $on): ?EmploymentTermsSnapshot;

    /**
     * @return list<EmploymentAssignmentSnapshot>
     */
    public function assignments(int $employmentId): array;

    public function assignmentAsOf(int $employmentId, string $on): ?EmploymentAssignmentSnapshot;

    /**
     * IAM users linked to the employment for any overlapping validity window.
     *
     * @return list<int>
     */
    public function linkedUserIds(int $employmentId, string $on): array;
}

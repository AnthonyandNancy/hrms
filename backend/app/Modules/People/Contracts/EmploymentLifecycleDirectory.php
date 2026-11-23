<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Effective-dated People facts needed by Lifecycle validation and read models.
 * All methods are tenant-scoped and use half-open business-date ranges.
 */
interface EmploymentLifecycleDirectory
{
    /**
     * @return list<EmploymentLifecycleSnapshot>
     */
    public function employmentsForPerson(int $tenantId, int $personId): array;

    public function employment(int $tenantId, int $employmentId, ?string $knownAt = null): ?EmploymentLifecycleSnapshot;

    /**
     * Whether another non-terminated employment for the same person and legal
     * entity overlaps the supplied half-open range.
     */
    public function hasOverlappingEmployment(int $tenantId, int $personId, int $legalEntityId, string $validFrom, string $validTo): bool;

    public function employeeNumberExists(int $tenantId, int $legalEntityId, string $employeeNo, ?int $exceptEmploymentId = null): bool;

    public function activeManagerAssignment(int $tenantId, int $managerAssignmentId, string $on): bool;

    public function primaryAssignmentId(int $tenantId, int $employmentId, string $on): ?int;

    public function isAssignmentActive(int $tenantId, int $assignmentId, string $on): bool;

    /**
     * @return list<EmploymentTermsSnapshot>
     */
    public function termsVersions(int $tenantId, int $employmentId): array;

    public function termsAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentTermsSnapshot;

    /**
     * @return list<EmploymentAssignmentSnapshot>
     */
    public function assignments(int $tenantId, int $employmentId): array;

    public function assignmentAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentAssignmentSnapshot;

    /**
     * IAM users linked to the employment for any overlapping validity window.
     *
     * @return list<int>
     */
    public function linkedUserIds(int $tenantId, int $employmentId, string $on): array;
}

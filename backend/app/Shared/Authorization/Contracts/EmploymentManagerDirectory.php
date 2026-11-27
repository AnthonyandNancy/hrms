<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Read-only People port used to resolve the approval line of an employment.
 * Workflow and IAM depend on this Shared contract; People owns the tables and
 * binds the Eloquent implementation.
 */
interface EmploymentManagerDirectory
{
    /**
     * User id of the manager of the given employment on the business date.
     */
    public function managerUserIdForEmployment(int $employmentId, string $businessDate): ?int;
}

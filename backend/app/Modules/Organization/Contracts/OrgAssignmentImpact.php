<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Read-only impact counts provided by People for org-move previews.
 */
final readonly class OrgAssignmentImpact
{
    public function __construct(
        public int $employeeCount,
        public int $managerCount,
    ) {}
}

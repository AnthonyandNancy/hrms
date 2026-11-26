<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Termination, transfer and long-leave eligibility. A pending task assigned
 * to an ineligible user must be reassigned, never silently approved.
 */
interface AssigneeEligibility
{
    public function isEligible(int $userId, string $businessDate): bool;
}

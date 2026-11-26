<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\AssigneeEligibility;

/**
 * No People facts are available in this module's reference binding. The
 * integration adapter checks assignment validity and long leave.
 */
final class DefaultAssigneeEligibility implements AssigneeEligibility
{
    public function isEligible(int $userId, string $businessDate): bool
    {
        return true;
    }
}

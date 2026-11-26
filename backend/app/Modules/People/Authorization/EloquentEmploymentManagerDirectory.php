<?php

declare(strict_types=1);

namespace App\Modules\People\Authorization;

use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\EmploymentUserLink;
use App\Shared\Authorization\Contracts\EmploymentManagerDirectory;
use App\Shared\Time\AsOfQuery;

/**
 * Production manager resolution for Workflow approval routing. People owns
 * assignments and employment/user links; Workflow only sees the user id.
 */
final class EloquentEmploymentManagerDirectory implements EmploymentManagerDirectory
{
    public function managerUserIdForEmployment(int $employmentId, string $businessDate): ?int
    {
        $managerEmploymentId = Assignment::query()
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('manager_assignment_id');

        if ($managerEmploymentId === null) {
            return null;
        }

        $managerEmployment = Assignment::query()
            ->whereKey((int) $managerEmploymentId)
            ->value('employment_id');

        if ($managerEmployment === null) {
            return null;
        }

        $userId = EmploymentUserLink::query()
            ->where('employment_id', (int) $managerEmployment)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('user_id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\AssigneeDirectory;

/**
 * Conservative reference binding: no implicit assignees. An integration task
 * binds People/Organization-backed resolution; until then templates that need
 * directory resolution fail closed with "no eligible assignee".
 */
final class DefaultAssigneeDirectory implements AssigneeDirectory
{
    public function managerUserIdForSubject(string $subjectType, int $subjectId, string $businessDate): ?int
    {
        return null;
    }

    /**
     * @return list<int>
     */
    public function orgHeadUserIdsForSubject(string $subjectType, int $subjectId, string $businessDate): array
    {
        return [];
    }

    /**
     * @return list<int>
     */
    public function userIdsWithRole(string $roleCode, string $businessDate): array
    {
        return [];
    }

    /**
     * @return list<int>
     */
    public function reassignmentCandidates(int $caseId, int $currentAssigneeId, string $businessDate): array
    {
        return [];
    }
}

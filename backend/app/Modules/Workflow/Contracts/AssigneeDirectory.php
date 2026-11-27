<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Resolves declarative assignee rules without knowing People/Organization
 * models. Integration tasks provide real implementations.
 */
interface AssigneeDirectory
{
    public function managerUserIdForSubject(string $subjectType, int $subjectId, string $businessDate): ?int;

    /**
     * @return list<int>
     */
    public function orgHeadUserIdsForSubject(string $subjectType, int $subjectId, string $businessDate): array;

    /**
     * @return list<int>
     */
    public function userIdsWithRole(string $roleCode, string $businessDate): array;

    /**
     * Candidate users for reassignment in priority order: new manager,
     * skip-level, HR admin. Implementations must not include ineligible users.
     *
     * @return list<int>
     */
    public function reassignmentCandidates(int $caseId, int $currentAssigneeId, string $businessDate): array;
}

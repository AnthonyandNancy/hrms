<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\AssigneeDirectory;

final class FakeAssigneeDirectory implements AssigneeDirectory
{
    /** @var array<string, int> */
    public array $managers = [];

    /** @var array<string, list<int>> */
    public array $orgHeads = [];

    /** @var array<string, list<int>> */
    public array $roleUsers = [];

    /** @var array<string, list<int>> */
    public array $reassignmentCandidates = [];

    public function managerUserIdForSubject(string $subjectType, int $subjectId, string $businessDate): ?int
    {
        return $this->managers["{$subjectType}:{$subjectId}:{$businessDate}"]
            ?? $this->managers["{$subjectType}:{$subjectId}"]
            ?? null;
    }

    /**
     * @return list<int>
     */
    public function orgHeadUserIdsForSubject(string $subjectType, int $subjectId, string $businessDate): array
    {
        return $this->orgHeads["{$subjectType}:{$subjectId}"] ?? [];
    }

    /**
     * @return list<int>
     */
    public function userIdsWithRole(string $roleCode, string $businessDate): array
    {
        return $this->roleUsers[$roleCode] ?? [];
    }

    /**
     * @return list<int>
     */
    public function reassignmentCandidates(int $caseId, int $currentAssigneeId, string $businessDate): array
    {
        return $this->reassignmentCandidates["{$caseId}:{$currentAssigneeId}"] ?? [];
    }
}

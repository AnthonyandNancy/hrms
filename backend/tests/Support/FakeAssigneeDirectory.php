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

    public function managerUserIdForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): ?int
    {
        return $this->managers["{$tenantId}:{$subjectType}:{$subjectId}:{$businessDate}"]
            ?? $this->managers["{$tenantId}:{$subjectType}:{$subjectId}"]
            ?? null;
    }

    /**
     * @return list<int>
     */
    public function orgHeadUserIdsForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): array
    {
        return $this->orgHeads["{$tenantId}:{$subjectType}:{$subjectId}"] ?? [];
    }

    /**
     * @return list<int>
     */
    public function userIdsWithRole(int $tenantId, string $roleCode, string $businessDate): array
    {
        return $this->roleUsers["{$tenantId}:{$roleCode}"] ?? [];
    }

    /**
     * @return list<int>
     */
    public function reassignmentCandidates(int $tenantId, int $caseId, int $currentAssigneeId, string $businessDate): array
    {
        return $this->reassignmentCandidates["{$tenantId}:{$caseId}:{$currentAssigneeId}"] ?? [];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\UserRole;
use App\Modules\Workflow\Contracts\AssigneeDirectory;
use Carbon\CarbonImmutable;

/**
 * Workflow assignee resolution backed by IAM role membership. The Workflow
 * module depends only on its own contract; IAM supplies the implementation.
 */
final class IamAssigneeDirectory implements AssigneeDirectory
{
    public function managerUserIdForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): ?int
    {
        return null;
    }

    /**
     * @return list<int>
     */
    public function orgHeadUserIdsForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): array
    {
        return [];
    }

    /**
     * @return list<int>
     */
    public function userIdsWithRole(int $tenantId, string $roleCode, string $businessDate): array
    {
        $asOf = CarbonImmutable::parse($businessDate, 'UTC')->endOfDay();

        return UserRole::query()
            ->where('tenant_id', $tenantId)
            ->where('role_code', $roleCode)
            ->where('valid_from', '<=', $asOf)
            ->where('valid_to', '>', $asOf)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function reassignmentCandidates(int $tenantId, int $caseId, int $currentAssigneeId, string $businessDate): array
    {
        return $this->userIdsWithRole($tenantId, 'HR_ADMIN', $businessDate);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\UserRole;
use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\Contracts\EmploymentManagerDirectory;

/**
 * Workflow assignee resolution backed by IAM role membership. The Workflow
 * module depends only on its own contract; IAM supplies the implementation.
 */
final class IamAssigneeDirectory implements AssigneeDirectory
{
    public function __construct(private readonly EmploymentManagerDirectory $managers) {}

    public function managerUserIdForSubject(string $subjectType, int $subjectId, string $businessDate): ?int
    {
        // Only employment subjects have an approval line. Any other subject
        // type stays unresolved so the case fails closed instead of guessing.
        if ($subjectType !== 'employment' || $subjectId <= 0) {
            return null;
        }

        return $this->managers->managerUserIdForEmployment($subjectId, $businessDate);
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
        $query = UserRole::query()
            ->where('role_code', $roleCode);

        return BindingValidity::whereActiveOn($query, $businessDate)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function reassignmentCandidates(int $caseId, int $currentAssigneeId, string $businessDate): array
    {
        return $this->userIdsWithRole('HR_ADMIN', $businessDate);
    }
}

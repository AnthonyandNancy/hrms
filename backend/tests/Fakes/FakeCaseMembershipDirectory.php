<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\CaseMembershipDirectory;

final class FakeCaseMembershipDirectory implements CaseMembershipDirectory
{
    /** @var array<int, list<int>> */
    public array $casesByUser = [];

    /** @var array<int, list<string>> map case => capability codes */
    public array $memberships = [];

    public function isMember(int $userId, int $caseId, string $capabilityCode): bool
    {
        return in_array($capabilityCode, $this->memberships[$caseId] ?? [], true)
            && in_array($caseId, $this->casesByUser[$userId] ?? [], true);
    }

    public function caseIdsForUser(int $userId, string $businessDate): array
    {
        return $this->casesByUser[$userId] ?? [];
    }
}

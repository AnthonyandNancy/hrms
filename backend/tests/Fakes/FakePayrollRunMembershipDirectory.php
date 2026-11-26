<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;

final class FakePayrollRunMembershipDirectory implements PayrollRunMembershipDirectory
{
    /** @var array<int, list<int>> */
    public array $runsByUser = [];

    /** @var array<int, list<string>> map run => capability codes */
    public array $memberships = [];

    public function isMember(int $userId, int $payrollRunId, string $capabilityCode): bool
    {
        return in_array($capabilityCode, $this->memberships[$payrollRunId] ?? [], true)
            && in_array($payrollRunId, $this->runsByUser[$userId] ?? [], true);
    }

    public function runIdsForUser(int $userId, string $businessDate): array
    {
        return $this->runsByUser[$userId] ?? [];
    }
}

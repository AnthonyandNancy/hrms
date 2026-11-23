<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\AssigneeEligibility;

final class FakeAssigneeEligibility implements AssigneeEligibility
{
    /** @var array<int, bool> */
    public array $eligible = [];

    /** @var array<string, bool> */
    public array $eligibleByTenantAndDate = [];

    public function isEligible(int $tenantId, int $userId, string $businessDate): bool
    {
        return $this->eligibleByTenantAndDate["{$tenantId}:{$userId}:{$businessDate}"]
            ?? $this->eligible[$userId]
            ?? true;
    }
}

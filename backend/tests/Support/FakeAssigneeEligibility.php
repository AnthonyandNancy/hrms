<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\AssigneeEligibility;

final class FakeAssigneeEligibility implements AssigneeEligibility
{
    /** @var array<int, bool> */
    public array $eligible = [];

    /** @var array<string, bool> */
    public array $eligibleByUserAndDate = [];

    public function isEligible(int $userId, string $businessDate): bool
    {
        return $this->eligibleByUserAndDate["{$userId}:{$businessDate}"]
            ?? $this->eligible[$userId]
            ?? true;
    }
}

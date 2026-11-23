<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Narrow read contract to IAM/user data. Workflow never queries IAM models;
 * Task 2 later binds a real implementation, tests use a fake.
 */
interface ActorDirectory
{
    public function snapshot(int $tenantId, int $userId): ?ActorSnapshot;
}

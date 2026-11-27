<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Narrow read contract to IAM/user data. Workflow never queries IAM models;
 * the IAM adapter supplies the implementation, tests use a fake.
 */
interface ActorDirectory
{
    public function snapshot(int $userId): ?ActorSnapshot;
}

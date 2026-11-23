<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Narrow demo-provisioning port. Demo seeders in other modules need to map a
 * synthetic login to its user id without importing IAM models.
 */
interface DemoUserDirectory
{
    public function userIdForUsername(int $tenantId, string $username): ?int;
}

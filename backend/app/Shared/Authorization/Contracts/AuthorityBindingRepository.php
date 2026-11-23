<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

use App\Shared\Authorization\BindingGrant;

/**
 * Tenant-safe binding lookup implemented by IAM. The authorizer receives only
 * value objects so it never imports IAM models.
 */
interface AuthorityBindingRepository
{
    /**
     * @return list<BindingGrant>
     */
    public function effectiveBindings(
        int $tenantId,
        int $principalId,
        string $capabilityCode,
        int $authVersion,
        string $businessDate,
    ): array;
}

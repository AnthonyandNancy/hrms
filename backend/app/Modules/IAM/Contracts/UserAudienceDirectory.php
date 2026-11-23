<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Tenant-scoped user audience facts used by engagement notices. IAM owns the
 * user and role read models; callers never import IAM models.
 */
interface UserAudienceDirectory
{
    /** @return list<int> */
    public function activeUserIds(int $tenantId): array;

    /**
     * @param  list<string>  $roleCodes
     * @return list<int>
     */
    public function userIdsWithRoles(int $tenantId, array $roleCodes, string $businessDate): array;

    /** @return list<string> */
    public function roleCodesForUser(int $tenantId, int $userId, string $businessDate): array;

    public function displayName(int $tenantId, int $userId): ?string;
}

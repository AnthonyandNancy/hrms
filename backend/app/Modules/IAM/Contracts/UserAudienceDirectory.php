<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * User audience facts used by engagement notices in the single-company instance. IAM owns the
 * user and role read models; callers never import IAM models.
 */
interface UserAudienceDirectory
{
    /** @return list<int> */
    public function activeUserIds(): array;

    /**
     * @param  list<string>  $roleCodes
     * @return list<int>
     */
    public function userIdsWithRoles(array $roleCodes, string $businessDate): array;

    /** @return list<string> */
    public function roleCodesForUser(int $userId, string $businessDate): array;

    public function displayName(int $userId): ?string;
}

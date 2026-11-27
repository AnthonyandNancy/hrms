<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Lifecycle offboarding contract. Revokes server-side sessions and increments
 * the user's auth_version so any already-issued session fails on next use.
 */
interface SessionRevoker
{
    /**
     * @return array{user_id:int, sessions_revoked:int, auth_version:int}|null
     */
    public function revokeForEmployment(int $employmentId, string $reason): ?array;

    /**
     * @return array{user_id:int, sessions_revoked:int, auth_version:int}|null
     */
    public function revokeForUser(int $userId, string $reason): ?array;
}

<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Narrow IAM read contract used by Lifecycle to link a natural person to an
 * access subject without importing IAM models.
 */
interface UserDirectory
{
    public function userIdForPerson(int $personId): ?int;

    /**
     * @return list<int>
     */
    public function userIdsForPerson(int $personId): array;
}

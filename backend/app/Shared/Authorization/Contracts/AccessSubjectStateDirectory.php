<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Live access-subject state used by the authorizer for hard-deny checks that
 * cannot be satisfied by the cached Principal alone.
 */
interface AccessSubjectStateDirectory
{
    public function currentAuthVersion(int $tenantId, int $userId): ?int;

    public function isEnabled(int $tenantId, int $userId): bool;
}

<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

interface WorkflowDelegationRevoker
{
    /**
     * Revoke every live delegation in which the user is principal or proxy.
     */
    public function revokeForUser(int $userId, string $reason): int;
}

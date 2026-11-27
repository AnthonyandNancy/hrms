<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowDelegationRevoker;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Shared\Time\Clock;

final class WorkflowDelegationRevocationService implements WorkflowDelegationRevoker
{
    public function __construct(private readonly Clock $clock) {}

    public function revokeForUser(int $userId, string $reason): int
    {
        return WorkflowDelegation::query()
            ->where('active', true)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($userId): void {
                $query->where('principal_user_id', $userId)
                    ->orWhere('proxy_user_id', $userId);
            })
            ->update([
                'active' => false,
                'revoked_at' => $this->clock->now(),
            ]);
    }
}

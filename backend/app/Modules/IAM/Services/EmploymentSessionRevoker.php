<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Contracts\SessionRevoker;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Shared\Time\Clock;

final class EmploymentSessionRevoker implements SessionRevoker
{
    public function __construct(
        private readonly Clock $clock,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    public function revokeForEmployment(int $tenantId, int $employmentId, string $reason): ?array
    {
        $userIds = $this->employments->linkedUserIds($tenantId, $employmentId, $this->clock->now()->toDateString());

        $last = null;

        foreach ($userIds as $userId) {
            $last = $this->revokeForUser($tenantId, $userId, $reason);
        }

        return $last;
    }

    public function revokeForUser(int $tenantId, int $userId, string $reason): ?array
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        $now = $this->clock->now();
        $revoked = (int) Session::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        $version = (int) $user->auth_version + 1;
        $user->forceFill(['auth_version' => $version])->save();

        return ['user_id' => (int) $user->id, 'sessions_revoked' => $revoked, 'auth_version' => $version];
    }
}

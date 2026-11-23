<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Models\User;
use App\Shared\Authorization\Contracts\AccessSubjectStateDirectory;

final class EloquentAccessSubjectStateDirectory implements AccessSubjectStateDirectory
{
    public function currentAuthVersion(int $tenantId, int $userId): ?int
    {
        $version = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->value('auth_version');

        return $version === null ? null : (int) $version;
    }

    public function isEnabled(int $tenantId, int $userId): bool
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }
}

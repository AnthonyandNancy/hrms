<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Models\User;
use App\Shared\Authorization\Contracts\AccessSubjectStateDirectory;

final class EloquentAccessSubjectStateDirectory implements AccessSubjectStateDirectory
{
    public function currentAuthVersion(int $userId): int
    {
        $version = User::query()
            ->whereKey($userId)
            ->value('auth_version');

        return $version === null ? 0 : (int) $version;
    }

    public function isEnabled(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\User;
use App\Shared\Health\ActiveAdministratorDirectory;

final class EloquentActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    public function count(): int
    {
        return User::query()
            ->where('is_privileged', true)
            ->where('status', User::STATUS_ACTIVE)
            ->count();
    }
}

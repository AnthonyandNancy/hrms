<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Contracts\DemoUserDirectory;
use App\Modules\IAM\Models\User;

final class EloquentDemoUserDirectory implements DemoUserDirectory
{
    public function userIdForUsername(int $tenantId, string $username): ?int
    {
        $id = User::query()
            ->where('tenant_id', $tenantId)
            ->where('username', $username)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}

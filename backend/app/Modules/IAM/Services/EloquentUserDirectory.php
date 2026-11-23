<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Contracts\UserDirectory;
use App\Modules\IAM\Models\User;

final class EloquentUserDirectory implements UserDirectory
{
    public function userIdForPerson(int $tenantId, int $personId): ?int
    {
        $id = User::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function userIdsForPerson(int $tenantId, int $personId): array
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}

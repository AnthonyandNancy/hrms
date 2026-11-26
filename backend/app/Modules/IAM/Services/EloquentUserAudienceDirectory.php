<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\BindingValidity;

/**
 * Read model for notice audiences in the single-company instance. IAM owns user and role rows;
 * Engagement depends only on the contract.
 */
final class EloquentUserAudienceDirectory implements UserAudienceDirectory
{
    /** @return list<int> */
    public function activeUserIds(): array
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $roleCodes
     * @return list<int>
     */
    public function userIdsWithRoles(array $roleCodes, string $businessDate): array
    {
        if ($roleCodes === []) {
            return [];
        }

        $query = UserRole::query()
            ->whereIn('role_code', $roleCodes);

        return BindingValidity::whereActiveOn($query, $businessDate)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function roleCodesForUser(int $userId, string $businessDate): array
    {
        $query = UserRole::query()
            ->where('user_id', $userId);

        return BindingValidity::whereActiveOn($query, $businessDate)
            ->orderBy('role_code')
            ->pluck('role_code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
    }

    public function displayName(int $userId): ?string
    {
        $name = User::query()
            ->whereKey($userId)
            ->value('username');

        return $name === null ? null : (string) $name;
    }
}

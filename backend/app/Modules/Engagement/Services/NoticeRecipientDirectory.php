<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleWorkspaceDirectory;

/**
 * Read-only recipient enrichment for notice receipts and whitelisted exports.
 *
 * Engagement never imports People or IAM models. The directory contracts keep
 * the owning modules authoritative, while this adapter only joins identifiers
 * for the small page currently being rendered or exported. Missing directory
 * facts stay null; they are never replaced with a fabricated employee number
 * or display name.
 */
final class NoticeRecipientDirectory
{
    public function __construct(
        private readonly ?PeopleDirectory $people = null,
        private readonly ?PeopleWorkspaceDirectory $profiles = null,
        private readonly ?UserAudienceDirectory $users = null,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{employment_id: int|null, employee_no: string|null, display_name: string|null}>
     */
    public function profilesForUsers(array $userIds, string $businessDate): array
    {
        $profiles = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            $profiles[(int) $userId] = $this->profileForUser((int) $userId, $businessDate);
        }

        return $profiles;
    }

    /**
     * @return array{employment_id: int|null, employee_no: string|null, display_name: string|null}
     */
    public function profileForUser(int $userId, string $businessDate): array
    {
        $people = $this->people ?? app(PeopleDirectory::class);
        $profiles = $this->profiles ?? app(PeopleWorkspaceDirectory::class);
        $users = $this->users ?? app(UserAudienceDirectory::class);

        $employmentId = $people->employmentForUser($userId);
        $snapshot = $employmentId !== null
            ? $people->employmentSnapshot($employmentId, $businessDate)
            : null;
        $profile = $employmentId !== null
            ? $profiles->profile($employmentId, $businessDate)
            : null;

        $displayName = $users->displayName($userId);
        if ($snapshot !== null && $snapshot->personName !== '') {
            $displayName = $snapshot->personName;
        }
        if ($profile !== null && $profile->personName !== '') {
            $displayName = $profile->personName;
        }

        return [
            'employment_id' => $employmentId,
            'employee_no' => $snapshot?->employeeNo,
            'display_name' => $displayName,
        ];
    }
}

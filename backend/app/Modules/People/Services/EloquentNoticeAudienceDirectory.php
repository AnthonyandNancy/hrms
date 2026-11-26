<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\EmploymentUserLink;
use App\Shared\Time\AsOfQuery;

final class EloquentNoticeAudienceDirectory implements NoticeAudienceDirectory
{
    /** @return list<int> */
    public function employmentIdsForOrgUnits(array $orgUnitIds, string $on): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        return Assignment::query()
            ->whereIn('org_unit_id', $orgUnitIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function userIdsForEmployments(array $employmentIds, string $on): array
    {
        if ($employmentIds === []) {
            return [];
        }

        return EmploymentUserLink::query()
            ->whereIn('employment_id', $employmentIds)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function employmentOrgUnitIds(int $employmentId, string $on): array
    {
        return Assignment::query()
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('org_unit_id')
            ->pluck('org_unit_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

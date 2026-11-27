<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Engagement audience read port over effective-dated assignments and user
 * links. It deliberately exposes only identifiers; Engagement never imports
 * People models.
 */
interface NoticeAudienceDirectory
{
    /**
     * @param  list<int>  $orgUnitIds
     * @return list<int>
     */
    public function employmentIdsForOrgUnits(array $orgUnitIds, string $on): array;

    /**
     * @param  list<int>  $employmentIds
     * @return list<int>
     */
    public function userIdsForEmployments(array $employmentIds, string $on): array;

    /**
     * @return list<int>
     */
    public function employmentOrgUnitIds(int $employmentId, string $on): array;
}

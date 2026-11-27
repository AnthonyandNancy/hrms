<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Organization reads People-owned assignment facts through this contract.
 * No cross-module model/table access is permitted.
 */
interface DepartmentStaffingDirectory
{
    /**
     * @param  list<int>  $orgUnitIds
     * @return array<int, int> org unit id => distinct active employments
     */
    public function activeHeadcount(array $orgUnitIds, string $on): array;

    /**
     * @return list<array{employment_id: int, assignment_id: int, assignment_type: string, is_primary: bool, valid_from: string, valid_to: string}>
     */
    public function members(int $orgUnitId, string $on): array;
}

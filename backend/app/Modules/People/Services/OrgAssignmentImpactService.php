<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrgAssignmentImpact;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use Illuminate\Support\Facades\DB;

final class OrgAssignmentImpactService implements OrgAssignmentImpactDirectory
{
    /**
     * @param  list<int>  $orgUnitIds
     */
    public function impactCounts(array $orgUnitIds, string $on): OrgAssignmentImpact
    {
        if ($orgUnitIds === []) {
            return new OrgAssignmentImpact(0, 0);
        }

        $query = DB::table('assignments')
            ->whereIn('org_unit_id', $orgUnitIds)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on);

        $employeeCount = (int) (clone $query)->distinct()->count('employment_id');
        $managerCount = (int) (clone $query)
            ->whereNotNull('manager_assignment_id')
            ->distinct()
            ->count('manager_assignment_id');

        return new OrgAssignmentImpact($employeeCount, $managerCount);
    }
}

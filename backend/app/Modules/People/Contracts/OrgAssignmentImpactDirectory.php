<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\Organization\Contracts\OrgAssignmentImpact;

/**
 * Enables Organization to preview a move without reading People tables.
 */
interface OrgAssignmentImpactDirectory
{
    /**
     * @param  list<int>  $orgUnitIds
     */
    public function impactCounts(array $orgUnitIds, string $on): OrgAssignmentImpact;
}

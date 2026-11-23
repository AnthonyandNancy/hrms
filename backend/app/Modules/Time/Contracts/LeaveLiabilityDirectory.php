<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Remaining leave balance projection for Reporting. It reports leave units in
 * minutes; monetary valuation needs Payroll contract inputs and is not
 * available on this branch.
 */
interface LeaveLiabilityDirectory
{
    /**
     * @return list<LeaveLiabilityRow>
     */
    public function balances(int $tenantId, string $asOf): array;
}

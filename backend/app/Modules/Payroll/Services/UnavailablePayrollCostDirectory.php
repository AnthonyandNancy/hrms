<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollCostRow;

final class UnavailablePayrollCostDirectory implements PayrollCostDirectory
{
    public function available(int $tenantId): bool
    {
        return false;
    }

    public function unavailableReason(int $tenantId): ?string
    {
        $reason = $tenantId <= 0 ? null : 'Payroll run tables exist, but no Payroll-owned Reporting projection publishes finalized cost by legal entity yet; payroll cost is unavailable rather than estimated from unfinalized runs.';

        return $reason;
    }

    /** @return list<PayrollCostRow> */
    public function costByEntity(int $tenantId, string $asOf): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollCostRow;

final class UnavailablePayrollCostDirectory implements PayrollCostDirectory
{
    public function available(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return 'Payroll run tables exist, but no Payroll-owned Reporting projection publishes finalized company cost yet; payroll cost is unavailable rather than estimated from unfinalized runs.';
    }

    /** @return list<PayrollCostRow> */
    public function costByEntity(string $asOf): array
    {
        return [];
    }
}

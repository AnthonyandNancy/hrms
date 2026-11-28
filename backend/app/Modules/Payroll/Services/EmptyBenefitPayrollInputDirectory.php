<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\BenefitPayrollInputDirectory;

final class EmptyBenefitPayrollInputDirectory implements BenefitPayrollInputDirectory
{
    public function frozenForPeriod(int $employmentId, string $period): array
    {
        return [];
    }
}

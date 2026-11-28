<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PerformanceBonusDirectory;

final class EmptyPerformanceBonusDirectory implements PerformanceBonusDirectory
{
    public function frozenForPeriod(int $employmentId, string $period): array
    {
        return [];
    }
}

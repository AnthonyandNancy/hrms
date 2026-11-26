<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\EmploymentTermsSnapshot;
use App\Shared\Time\BusinessDate;

/**
 * Payroll-facing terms port. It delegates to the single People-owned
 * effective-dated terms implementation so there is exactly one as-of
 * resolution rule for salary history.
 */
final class EmploymentTermsDirectoryService implements EmploymentTermsDirectory
{
    public function __construct(private readonly EmploymentLifecycleDirectory $lifecycle) {}

    public function termsAsOf(int $employmentId, string $on): ?EmploymentTermsSnapshot
    {
        return $this->lifecycle->termsAsOf($employmentId, BusinessDate::from($on)->value());
    }
}

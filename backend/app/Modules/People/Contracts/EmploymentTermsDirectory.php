<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Payroll-facing terms contract. The existing frozen PeopleDirectory contract
 * is unchanged; this narrow port owns only salary-bearing terms versions.
 */
interface EmploymentTermsDirectory
{
    public function termsAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentTermsSnapshot;
}

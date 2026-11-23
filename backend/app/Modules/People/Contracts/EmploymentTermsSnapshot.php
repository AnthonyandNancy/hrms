<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Immutable employment-terms projection for payroll. Payroll never reads the
 * People-owned employment_terms table; it resolves the terms version effective
 * on the earning period through this contract.
 */
final readonly class EmploymentTermsSnapshot
{
    public function __construct(
        public int $termsId,
        public int $employmentId,
        public int $version,
        public string $effectiveFrom,
        public string $effectiveTo,
        public ?int $baseSalaryCents,
        public ?string $salaryCurrency,
        public ?string $salarySchemeCode,
        public string $workLocation,
        public string $workSchedule,
    ) {}
}

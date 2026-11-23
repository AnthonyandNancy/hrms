<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Immutable employment-terms projection. Lifecycle and Payroll never read the
 * People-owned employment_terms table; they resolve the terms version
 * effective on a business date through a People contract.
 *
 * Salary is integer minor units. `baseSalaryMinor` is the canonical storage
 * name; payroll names the same amount `baseSalaryCents` in its own domain.
 */
final readonly class EmploymentTermsSnapshot
{
    public function __construct(
        public int $id,
        public int $employmentId,
        public int $version,
        public string $effectiveFrom,
        public string $effectiveTo,
        public ?string $probationEndDate,
        public string $workLocation,
        public string $workSchedule,
        public ?string $salarySchemeCode,
        public ?int $baseSalaryMinor,
        public string $salaryCurrency,
        public int $noticePeriodDays,
        public bool $confidentiality,
        public bool $nonCompete,
        public ?string $signedAt,
    ) {}
}

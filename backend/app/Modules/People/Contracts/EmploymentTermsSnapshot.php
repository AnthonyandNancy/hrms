<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

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

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

final readonly class PayrollCostRow
{
    public function __construct(
        public int $grossCents,
        public int $netCents,
        public int $employerCostCents,
        public string $currencyCode,
    ) {}
}

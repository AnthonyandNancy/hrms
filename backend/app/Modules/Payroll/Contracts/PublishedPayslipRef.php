<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Identifier-only reference to a published payslip. Monetary values are never
 * part of a notification contract.
 */
final readonly class PublishedPayslipRef
{
    public function __construct(
        public int $payslipId,
        public int $employmentId,
        public int $runId,
        public string $period,
    ) {}
}

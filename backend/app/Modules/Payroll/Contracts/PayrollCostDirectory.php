<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Reporting port owned by Payroll. Finalized payroll runs exist, but this
 * reference binding does not yet publish a company cost projection, so it
 * reports the metric as unavailable rather than fabricating cost.
 */
interface PayrollCostDirectory
{
    public function available(): bool;

    public function unavailableReason(): ?string;

    /**
     * @return list<PayrollCostRow>
     */
    public function costByEntity(string $asOf): array;
}

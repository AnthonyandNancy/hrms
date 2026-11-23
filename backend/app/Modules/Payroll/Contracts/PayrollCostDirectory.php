<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Reporting port owned by Payroll. This branch has the payroll calculation
 * core but no payroll-run tables, so the reference binding reports the metric
 * as unavailable rather than fabricating cost figures.
 */
interface PayrollCostDirectory
{
    public function available(int $tenantId): bool;

    public function unavailableReason(int $tenantId): ?string;

    /**
     * @return list<PayrollCostRow>
     */
    public function costByEntity(int $tenantId, string $asOf): array;
}

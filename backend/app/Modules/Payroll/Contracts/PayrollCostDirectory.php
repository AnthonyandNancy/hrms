<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Reporting port owned by Payroll. Finalized payroll runs exist, but this
 * reference binding does not yet publish a cost-by-legal-entity projection,
 * so it reports the metric as unavailable rather than fabricating cost.
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

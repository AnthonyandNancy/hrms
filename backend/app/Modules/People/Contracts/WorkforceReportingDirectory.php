<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Point-in-time workforce projection for Reporting. Reporting consumes only
 * this contract and never imports People models or tables.
 */
interface WorkforceReportingDirectory
{
    /**
     * @return list<WorkforceReportingRow>
     */
    public function rows(int $tenantId, string $on): array;
}

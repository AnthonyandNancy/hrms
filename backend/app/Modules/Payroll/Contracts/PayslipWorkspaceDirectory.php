<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Self-scoped payslip projection for the personal workspace. IAM composes this
 * port and never reads Payroll-owned tables.
 */
interface PayslipWorkspaceDirectory
{
    /**
     * @return list<array{id: int, period: string, payDate: string|null, netCents: int, state: string}>
     */
    public function recentForEmployment(int $tenantId, int $employmentId, int $limit = 2): array;
}

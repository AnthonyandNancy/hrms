<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Payroll-owned read seam used by Engagement to build and revalidate
 * identifier-only payslip notifications. It never returns amounts.
 */
interface PayslipNotificationDirectory
{
    public function publishedPayslip(int $payslipId): ?PublishedPayslipRef;

    /** @return list<PublishedPayslipRef> */
    public function publishedForRun(int $runId): array;
}

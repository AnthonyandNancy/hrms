<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayslipNotificationDirectory;
use App\Modules\Payroll\Contracts\PublishedPayslipRef;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;

/**
 * Payroll-owned, amount-free implementation of the notification directory.
 * Only published payslips are visible and monetary columns are never selected.
 */
final class EloquentPayslipNotificationDirectory implements PayslipNotificationDirectory
{
    public function publishedPayslip(int $payslipId): ?PublishedPayslipRef
    {
        $payslip = Payslip::query()
            ->whereKey($payslipId)
            ->whereNotNull('published_at')
            ->first(['id', 'employment_id', 'run_id', 'published_at']);

        if ($payslip === null) {
            return null;
        }

        $run = PayrollRun::query()
            ->whereKey((int) $payslip->run_id)
            ->first(['id', 'period']);

        return new PublishedPayslipRef(
            payslipId: (int) $payslip->id,
            employmentId: (int) $payslip->employment_id,
            runId: (int) $payslip->run_id,
            period: $run !== null ? (string) $run->period : '',
        );
    }

    /**
     * @return list<PublishedPayslipRef>
     */
    public function publishedForRun(int $runId): array
    {
        $run = PayrollRun::query()->whereKey($runId)->first(['id', 'period']);

        if ($run === null) {
            return [];
        }

        return Payslip::query()
            ->where('run_id', $runId)
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->get(['id', 'employment_id', 'run_id'])
            ->map(static fn (Payslip $payslip): PublishedPayslipRef => new PublishedPayslipRef(
                payslipId: (int) $payslip->id,
                employmentId: (int) $payslip->employment_id,
                runId: (int) $payslip->run_id,
                period: (string) $run->period,
            ))
            ->all();
    }
}

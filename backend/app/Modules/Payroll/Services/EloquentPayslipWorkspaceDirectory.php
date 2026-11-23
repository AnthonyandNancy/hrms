<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayslipWorkspaceDirectory;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;

/**
 * Bounded self-scoped payslip teaser. Only published payslips for the caller's
 * own employment are returned; amounts stay in integer cents.
 */
final class EloquentPayslipWorkspaceDirectory implements PayslipWorkspaceDirectory
{
    /**
     * @return list<array{id: int, period: string, payDate: string|null, netCents: int, state: string}>
     */
    public function recentForEmployment(int $tenantId, int $employmentId, int $limit = 2): array
    {
        $payslips = Payslip::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(max(1, min(20, $limit)))
            ->get();

        if ($payslips->isEmpty()) {
            return [];
        }

        $runs = PayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $payslips->pluck('run_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($payslips as $payslip) {
            $run = $runs->get((int) $payslip->run_id);
            $items[] = [
                'id' => (int) $payslip->id,
                'period' => $run !== null ? (string) $run->period : '',
                'payDate' => $run !== null ? (string) $run->pay_date : null,
                'netCents' => (int) $payslip->net_cents,
                'state' => $run !== null && $run->correction_of_run_id !== null ? 'CORRECTION' : 'PUBLISHED',
            ];
        }

        return $items;
    }
}

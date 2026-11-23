<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollYtd;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Owns payroll_ytd. Only published runs are folded into the cumulative ledger;
 * corrections add their signed deltas. All arithmetic is integer cents checked
 * through bcmath.
 */
final class PayrollYtdService
{
    public function __construct(private readonly Clock $clock) {}

    public function opening(int $tenantId, int $employmentId, int $taxYear): PayrollYtd
    {
        $row = PayrollYtd::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('tax_year', $taxYear)
            ->first();

        return $row ?? new PayrollYtd([
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'tax_year' => $taxYear,
            'taxable_income_cents' => 0,
            'tax_withheld_cents' => 0,
            'social_insurance_cents' => 0,
            'housing_fund_cents' => 0,
            'gross_cents' => 0,
            'net_cents' => 0,
            'carry_forward_cents' => 0,
        ]);
    }

    public function apply(PayrollRun $run, PayrollRunEmployee $employee): PayrollYtd
    {
        $status = $run->status instanceof PayrollRunStatus
            ? $run->status
            : PayrollRunStatus::from((string) $run->status);

        if (! $status->isSealed()) {
            throw new ConflictException('YTD is only updated by a published run.', [
                'reason' => 'RUN_NOT_PUBLISHED',
                'status' => $status->value,
            ]);
        }

        return $this->applyForPublication($run, $employee);
    }

    /**
     * Internal publication-time fold. The caller is the publish transaction,
     * which transitions the run to PUBLISHED immediately after this call.
     */
    public function applyForPublication(PayrollRun $run, PayrollRunEmployee $employee): PayrollYtd
    {
        return DB::transaction(function () use ($run, $employee): PayrollYtd {
            $taxYear = (int) substr((string) $run->period, 0, 4);
            $row = PayrollYtd::query()
                ->where('tenant_id', $run->tenant_id)
                ->where('employment_id', $employee->employment_id)
                ->where('tax_year', $taxYear)
                ->lockForUpdate()
                ->first();

            $row ??= new PayrollYtd([
                'tenant_id' => $run->tenant_id,
                'employment_id' => $employee->employment_id,
                'tax_year' => $taxYear,
                'taxable_income_cents' => 0,
                'tax_withheld_cents' => 0,
                'social_insurance_cents' => 0,
                'housing_fund_cents' => 0,
                'gross_cents' => 0,
                'net_cents' => 0,
                'carry_forward_cents' => 0,
            ]);

            $row->forceFill([
                'taxable_income_cents' => $this->nonNegativeAdd((int) $row->taxable_income_cents, (int) $employee->taxable_cents),
                'tax_withheld_cents' => $this->nonNegativeAdd((int) $row->tax_withheld_cents, (int) $employee->tax_cents),
                'social_insurance_cents' => $this->nonNegativeAdd((int) $row->social_insurance_cents, (int) $employee->employee_si_cents),
                'housing_fund_cents' => $this->nonNegativeAdd((int) $row->housing_fund_cents, (int) $employee->employee_hf_cents),
                'gross_cents' => $this->nonNegativeAdd((int) $row->gross_cents, (int) $employee->gross_cents),
                'net_cents' => $this->nonNegativeAdd((int) $row->net_cents, (int) $employee->net_cents),
                'last_run_id' => (int) $run->id,
                'source_type' => 'PAYROLL',
                'source_id' => (int) $employee->id,
                'updated_at' => $this->clock->now(),
                'created_at' => $row->created_at ?? $this->clock->now(),
            ])->save();

            $employee->forceFill([
                'ytd_applied' => true,
                'ytd_applied_at' => $this->clock->now(),
            ])->save();

            return $row->refresh();
        });
    }

    private function nonNegativeAdd(int $left, int $right): int
    {
        $result = bcadd((string) $left, (string) $right, 0);

        if (bccomp($result, '0', 0) < 0) {
            return 0;
        }

        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0) {
            throw new \InvalidArgumentException('YTD total exceeds native integer range.');
        }

        return (int) $result;
    }
}

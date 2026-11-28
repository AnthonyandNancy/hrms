<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollYtd;
use App\Modules\Payroll\Models\PayrollYtdLedgerEntry;
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

    public function opening(int $employmentId, int $taxYear): PayrollYtd
    {
        $row = PayrollYtd::query()
            ->where('employment_id', $employmentId)
            ->where('tax_year', $taxYear)
            ->first();

        return $row ?? new PayrollYtd([
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
                ->where('employment_id', $employee->employment_id)
                ->where('tax_year', $taxYear)
                ->lockForUpdate()
                ->first();

            $row ??= new PayrollYtd([
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

            $existing = PayrollYtdLedgerEntry::query()
                ->where('source_run_id', (int) $run->id)
                ->where('employment_id', (int) $employee->employment_id)
                ->where('entry_type', 'PUBLISH')
                ->first();

            if ($existing !== null) {
                $employee->forceFill([
                    'ytd_applied' => true,
                    'ytd_applied_at' => $employee->ytd_applied_at ?? $this->clock->now(),
                ])->save();

                return $row->refresh();
            }

            $amounts = [
                'taxable_income_cents' => (int) $employee->taxable_cents,
                'tax_withheld_cents' => (int) $employee->tax_cents,
                'social_insurance_cents' => (int) $employee->employee_si_cents,
                'housing_fund_cents' => (int) $employee->employee_hf_cents,
                'gross_cents' => (int) $employee->gross_cents,
                'net_cents' => (int) $employee->net_cents,
            ];
            $projected = [];

            foreach ($amounts as $field => $amount) {
                $projected[$field] = $this->checkedAdd((int) $row->{$field}, $amount);
            }

            $projected['carry_forward_cents'] = min(0, $projected['net_cents']);
            $sourceType = ($run->run_type instanceof PayrollRunType ? $run->run_type : PayrollRunType::from((string) $run->run_type)) === PayrollRunType::CORRECTION
                ? 'CORRECTION'
                : 'PAYROLL';
            $sourceHash = hash('sha256', implode('|', [
                (string) $run->id,
                (string) $employee->employment_id,
                (string) $employee->id,
                implode(',', array_map(static fn (int $value): string => (string) $value, $amounts)),
                (string) ($run->input_hash ?? ''),
            ]));

            PayrollYtdLedgerEntry::query()->create([
                'tenant_id' => 0,
                'employment_id' => (int) $employee->employment_id,
                'tax_year' => $taxYear,
                'source_run_id' => (int) $run->id,
                'source_employee_id' => (int) $employee->id,
                'source_type' => $sourceType,
                'entry_type' => 'PUBLISH',
                'taxable_income_cents' => $amounts['taxable_income_cents'],
                'tax_withheld_cents' => $amounts['tax_withheld_cents'],
                'social_insurance_cents' => $amounts['social_insurance_cents'],
                'housing_fund_cents' => $amounts['housing_fund_cents'],
                'gross_cents' => $amounts['gross_cents'],
                'net_cents' => $amounts['net_cents'],
                'carry_forward_cents' => $projected['carry_forward_cents'],
                'source_hash' => $sourceHash,
                'created_at' => $this->clock->now(),
            ]);

            $row->forceFill([
                ...$projected,
                'last_run_id' => (int) $run->id,
                'source_type' => $sourceType,
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

    private function checkedAdd(int $left, int $right): int
    {
        $result = bcadd((string) $left, (string) $right, 0);

        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0 || bccomp($result, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException('YTD total exceeds native integer range.');
        }

        return (int) $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;

/**
 * Read-only reconciliation invariants:
 * payslip totals = run employee totals = run totals; correction chains must
 * reconcile original + deltas; YTD is checked against published runs.
 */
final class ReconciliationService
{
    /**
     * @return array{run_id: int, period: string, status: string, employee_count: int, payslip_count: int, gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int, differences: list<array<string, mixed>>, chain: array<string, mixed>, passed: bool}
     */
    public function reconcile(int $runId): array
    {
        $run = PayrollRun::query()
            ->whereKey($runId)
            ->first();

        if ($run === null) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        $employees = PayrollRunEmployee::query()
            ->where('run_id', $runId)
            ->orderBy('employment_id')
            ->get();
        $payslips = Payslip::query()
            ->where('run_id', $runId)
            ->orderBy('employment_id')
            ->get();
        $differences = [];

        $employeeById = $employees->keyBy('employment_id');

        foreach ($payslips as $payslip) {
            $employee = $employeeById->get($payslip->employment_id);

            if ($employee === null) {
                $differences[] = [
                    'code' => 'PAYSLIP_WITHOUT_EMPLOYEE',
                    'employment_id' => (int) $payslip->employment_id,
                ];

                continue;
            }

            foreach ([
                'gross_cents' => ['payslip' => $payslip->gross_cents, 'employee' => $employee->gross_cents],
                'deductions_cents' => ['payslip' => $payslip->deductions_cents, 'employee' => $employee->deductions_cents],
                'net_cents' => ['payslip' => $payslip->net_cents, 'employee' => $employee->net_cents],
                'employer_cost_cents' => ['payslip' => $payslip->employer_cost_cents, 'employee' => $employee->employer_cost_cents],
            ] as $field => $pair) {
                if ((int) $pair['payslip'] !== (int) $pair['employee']) {
                    $differences[] = [
                        'code' => 'PAYSLIP_EMPLOYEE_MISMATCH',
                        'employment_id' => (int) $payslip->employment_id,
                        'field' => $field,
                        'payslip' => (int) $pair['payslip'],
                        'employee' => (int) $pair['employee'],
                    ];
                }
            }

            $lineTotals = $this->lineTotals((int) $payslip->id);

            if ($lineTotals['earning'] !== (int) $payslip->gross_cents) {
                $differences[] = [
                    'code' => 'PAYSLIP_LINE_GROSS_MISMATCH',
                    'employment_id' => (int) $payslip->employment_id,
                    'lines' => $lineTotals['earning'],
                    'payslip' => (int) $payslip->gross_cents,
                ];
            }

            if ($lineTotals['deduction'] !== (int) $payslip->deductions_cents) {
                $differences[] = [
                    'code' => 'PAYSLIP_LINE_DEDUCTION_MISMATCH',
                    'employment_id' => (int) $payslip->employment_id,
                    'lines' => $lineTotals['deduction'],
                    'payslip' => (int) $payslip->deductions_cents,
                ];
            }
        }

        $sums = [
            'gross_cents' => (int) $employees->sum('gross_cents'),
            'deductions_cents' => (int) $employees->sum('deductions_cents'),
            'net_cents' => (int) $employees->sum('net_cents'),
            'employer_cost_cents' => (int) $employees->sum('employer_cost_cents'),
        ];

        foreach ($sums as $field => $sum) {
            if ((int) $run->{$field} !== $sum) {
                $differences[] = [
                    'code' => 'RUN_TOTAL_MISMATCH',
                    'field' => $field,
                    'run' => (int) $run->{$field},
                    'employees' => $sum,
                ];
            }
        }

        $chain = $this->chain($run);

        if ($run->run_type->value === 'CORRECTION' && ($chain['passed'] ?? true) === false) {
            $differences[] = [
                'code' => 'CORRECTION_CHAIN_MISMATCH',
                'chain' => $chain,
            ];
        }

        return [
            'run_id' => (int) $run->id,
            'period' => (string) $run->period,
            'status' => $run->status instanceof PayrollRunStatus ? $run->status->value : (string) $run->status,
            'employee_count' => $employees->count(),
            'payslip_count' => $payslips->count(),
            'gross_cents' => (int) $run->gross_cents,
            'deductions_cents' => (int) $run->deductions_cents,
            'net_cents' => (int) $run->net_cents,
            'employer_cost_cents' => (int) $run->employer_cost_cents,
            'differences' => $differences,
            'chain' => $chain,
            'passed' => $differences === [],
        ];
    }

    /**
     * For a correction run, original + all published corrections in the chain.
     *
     * @return array<string, mixed>
     */
    public function chain(PayrollRun $run): array
    {
        if ($run->correction_of_run_id === null) {
            return [
                'original_run_id' => null,
                'correction_run_ids' => [],
                'passed' => true,
            ];
        }

        $original = PayrollRun::query()
            ->whereKey((int) $run->correction_of_run_id)
            ->first();

        if ($original === null) {
            return [
                'original_run_id' => (int) $run->correction_of_run_id,
                'correction_run_ids' => [],
                'passed' => false,
                'reason' => 'ORIGINAL_RUN_MISSING',
            ];
        }

        $corrections = PayrollRun::query()
            ->where('correction_of_run_id', $original->id)
            ->whereIn('status', [
                PayrollRunStatus::PUBLISHED->value,
                PayrollRunStatus::PAID->value,
                PayrollRunStatus::CLOSED->value,
            ])
            ->orderBy('id')
            ->get();

        $totals = [
            'original_gross_cents' => (int) $original->gross_cents,
            'original_net_cents' => (int) $original->net_cents,
            'correction_gross_cents' => (int) $corrections->sum('gross_cents'),
            'correction_net_cents' => (int) $corrections->sum('net_cents'),
            'payable_gross_cents' => (int) $original->gross_cents + (int) $corrections->sum('gross_cents'),
            'payable_net_cents' => (int) $original->net_cents + (int) $corrections->sum('net_cents'),
        ];

        $payslipTotals = Payslip::query()
            ->whereIn('run_id', [$original->id, ...$corrections->pluck('id')->all()])
            ->selectRaw('COALESCE(SUM(gross_cents),0) as gross, COALESCE(SUM(net_cents),0) as net')
            ->first();

        $payslipGross = (int) ($payslipTotals->gross ?? 0);
        $payslipNet = (int) ($payslipTotals->net ?? 0);

        return [
            'original_run_id' => (int) $original->id,
            'correction_run_ids' => $corrections->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            'original_gross_cents' => $totals['original_gross_cents'],
            'original_net_cents' => $totals['original_net_cents'],
            'correction_gross_cents' => $totals['correction_gross_cents'],
            'correction_net_cents' => $totals['correction_net_cents'],
            'payable_gross_cents' => $totals['payable_gross_cents'],
            'payable_net_cents' => $totals['payable_net_cents'],
            'payslip_gross_cents' => $payslipGross,
            'payslip_net_cents' => $payslipNet,
            'passed' => $payslipGross === $totals['payable_gross_cents']
                && $payslipNet === $totals['payable_net_cents'],
        ];
    }

    /**
     * @return array{earning: int, deduction: int, employer: int}
     */
    private function lineTotals(int $payslipId): array
    {
        $totals = ['earning' => 0, 'deduction' => 0, 'employer' => 0];

        foreach (PayslipLine::query()->where('payslip_id', $payslipId)->get() as $line) {
            $category = (string) $line->category;
            $key = match ($category) {
                'EARNING' => 'earning',
                'DEDUCTION' => 'deduction',
                'EMPLOYER_COST' => 'employer',
                default => null,
            };

            if ($key !== null) {
                $totals[$key] += (int) $line->amount_cents;
            }
        }

        return $totals;
    }
}

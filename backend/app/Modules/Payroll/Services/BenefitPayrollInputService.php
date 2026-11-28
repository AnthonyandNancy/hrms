<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\BenefitPayrollInputDirectory;
use App\Modules\Payroll\Models\BenefitEnrollment;
use App\Modules\Payroll\Models\BenefitPlan;
use App\Modules\Payroll\Models\BenefitTransaction;

/**
 * Payroll-owned adapter for the benefit cost ledger. RECORD rows add cost and
 * REVERSAL rows subtract it without mutating the original row. Contributions
 * are netted per enrollment/plan so one employee may have multiple plans in a
 * single period.
 */
final class BenefitPayrollInputService implements BenefitPayrollInputDirectory
{
    public function frozenForPeriod(int $employmentId, string $period): array
    {
        $transactions = BenefitTransaction::query()
            ->where('employment_id', $employmentId)
            ->where('period', $period)
            ->orderBy('enrollment_id')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $enrollmentIds = $transactions->pluck('enrollment_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $enrollments = BenefitEnrollment::query()->whereIn('id', $enrollmentIds)->get()->keyBy('id');
        $planIds = $enrollments->pluck('plan_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $plans = BenefitPlan::query()->whereIn('id', $planIds)->get()->keyBy('id');
        $byEnrollment = [];

        foreach ($transactions as $transaction) {
            $enrollmentId = (int) $transaction->enrollment_id;
            $sign = $transaction->entry_type === 'REVERSAL' ? -1 : 1;
            $byEnrollment[$enrollmentId] ??= [
                'employee' => 0,
                'employer' => 0,
                'transaction_ids' => [],
            ];
            $byEnrollment[$enrollmentId]['employee'] += $sign * (int) $transaction->employee_amount_cents;
            $byEnrollment[$enrollmentId]['employer'] += $sign * (int) $transaction->employer_amount_cents;
            $byEnrollment[$enrollmentId]['transaction_ids'][] = (int) $transaction->id;
        }

        $result = [];

        foreach ($byEnrollment as $enrollmentId => $amounts) {
            $employee = max(0, (int) $amounts['employee']);
            $employer = max(0, (int) $amounts['employer']);

            if ($employee === 0 && $employer === 0) {
                continue;
            }

            $enrollment = $enrollments->get($enrollmentId);
            $plan = $enrollment === null ? null : $plans->get((int) $enrollment->plan_id);
            $code = $plan !== null ? (string) $plan->code : 'PLAN-'.$enrollmentId;
            $label = $plan !== null ? (string) $plan->name : 'Benefit plan '.$enrollmentId;

            $result[] = [
                'item_code' => 'BENEFIT_'.strtoupper($code),
                'label' => $label,
                'employee_amount_cents' => $employee,
                'employer_amount_cents' => $employer,
                'source_type' => 'benefit_transaction',
                'source_id' => 'enrollment:'.$enrollmentId.'|tx:'.implode(',', $amounts['transaction_ids']),
                'taxable' => false,
                'sort_order' => 800 + $enrollmentId,
            ];
        }

        usort($result, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $result;
    }
}

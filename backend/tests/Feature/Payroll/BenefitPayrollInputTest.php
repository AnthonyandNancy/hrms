<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\BenefitEnrollment;
use App\Modules\Payroll\Models\BenefitPlan;
use App\Modules\Payroll\Models\BenefitTransaction;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\BenefitService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\PayrollTestCase;

final class BenefitPayrollInputTest extends PayrollTestCase
{
    public function test_multiple_benefit_plans_enter_employee_deductions_and_employer_cost(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            ['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560],
        ]);

        $medical = $this->plan('MEDICAL', 'Medical supplement', '0.160000', '0.080000');
        $supplementary = $this->plan('SUPPLEMENTARY', 'Supplementary medical', '0.030000', '0.020000');
        $first = $this->enrollment($employmentId, $medical, 1_000_000, '2026-01-01');
        $second = $this->enrollment($employmentId, $supplementary, 1_000_000, '2026-01-01');
        $service = app(BenefitService::class);
        $firstTransaction = $service->createTransaction(10, ['enrollment_id' => (int) $first->id, 'period' => '2026-01']);
        $service->createTransaction(10, ['enrollment_id' => (int) $second->id, 'period' => '2026-01']);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $snapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertCount(2, (array) $snapshot->payload_json['benefits']);
        $service->reverseTransaction(11, (int) $firstTransaction->id, 'Synthetic post-lock reversal must not alter the frozen snapshot.');
        $this->assertCount(2, (array) $snapshot->refresh()->payload_json['benefits']);

        $result = app(CalculationService::class)->calculate($run, 10);
        $this->assertSame(1_000_000, $result['gross_cents']);
        $employee = PayrollRunEmployee::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(1_420_000, (int) $employee->employer_cost_cents);
        $this->assertSame(739_500, (int) $employee->net_cents);

        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $lines = PayslipLine::query()->where('payslip_id', $payslip->id)->get();
        $employeeLines = $lines->where('category', 'DEDUCTION')->whereIn('item_code', ['BENEFIT_MEDICAL', 'BENEFIT_SUPPLEMENTARY']);
        $this->assertSame(100_000, (int) $employeeLines->sum('amount_cents'));
        $this->assertTrue($lines->contains(static fn (PayslipLine $line): bool => $line->item_code === 'BENEFIT_MEDICAL_EMPLOYER'));
        $this->assertSame(190_000, (int) $lines->where('category', 'EMPLOYER_COST')->whereIn('item_code', ['BENEFIT_MEDICAL_EMPLOYER', 'BENEFIT_SUPPLEMENTARY_EMPLOYER'])->sum('amount_cents'));
    }

    public function test_reversal_append_is_netted_and_cannot_be_repeated(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-02', [
            ['business_date' => '2026-02-01', 'scheduled_minutes' => 9_600, 'worked_minutes' => 9_600],
        ]);

        $plan = $this->plan('DENTAL', 'Dental', '0.100000', '0.050000');
        $enrollment = $this->enrollment($employmentId, $plan, 1_000_000, '2026-02-01');
        $service = app(BenefitService::class);
        $transaction = $service->createTransaction(10, ['enrollment_id' => (int) $enrollment->id, 'period' => '2026-02']);
        $reversal = $service->reverseTransaction(11, (int) $transaction->id, 'Synthetic duplicate contribution reversal.');

        $this->assertSame('REVERSAL', (string) $reversal->entry_type);
        $this->assertSame((int) $transaction->id, (int) $reversal->reversal_of_id);
        $this->assertSame(2, BenefitTransaction::query()->count());

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-02']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $snapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame([], (array) $snapshot->payload_json['benefits']);

        $this->expectException(ConflictException::class);
        $service->reverseTransaction(11, (int) $transaction->id, 'Synthetic duplicate reversal attempt.');
    }

    private function plan(string $code, string $name, string $employerRate, string $employeeRate): BenefitPlan
    {
        return BenefitPlan::query()->create([
            'code' => $code,
            'name' => $name,
            'employer_rate' => $employerRate,
            'employee_rate' => $employeeRate,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'version' => 1,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function enrollment(int $employmentId, BenefitPlan $plan, int $baseCents, string $from): BenefitEnrollment
    {
        return BenefitEnrollment::query()->create([
            'employment_id' => $employmentId,
            'plan_id' => (int) $plan->id,
            'base_amount_cents' => $baseCents,
            'effective_from' => $from,
            'effective_to' => null,
            'version' => 1,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }
}

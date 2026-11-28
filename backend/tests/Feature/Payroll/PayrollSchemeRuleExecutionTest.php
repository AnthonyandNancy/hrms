<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollEmployeeStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

final class PayrollSchemeRuleExecutionTest extends PayrollTestCase
{
    public function test_fixed_percent_attendance_and_formula_items_execute_as_integer_cents(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            ['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560],
        ]);

        $scheme = $base['scheme'];
        foreach ([
            ['code' => 'MEAL', 'calculation' => 'FIXED', 'amount' => 38_000, 'rate' => null, 'formula' => null],
            ['code' => 'PCT_BONUS', 'calculation' => 'PERCENT', 'amount' => 0, 'rate' => 50_000, 'formula' => null],
            ['code' => 'ATTENDANCE_BONUS', 'calculation' => 'ATTENDANCE_PRORATE', 'amount' => 10_000, 'rate' => null, 'formula' => null],
            ['code' => 'FORMULA_BONUS', 'calculation' => 'FORMULA_LIMITED', 'amount' => 0, 'rate' => null, 'formula' => [
                'type' => 'if',
                'condition' => [
                    'type' => 'compare',
                    'op' => 'GTE',
                    'left' => ['type' => 'var', 'name' => 'base_salary_cents'],
                    'right' => ['type' => 'const', 'value' => '900000'],
                ],
                'then' => ['type' => 'const', 'value' => '50000'],
                'else' => ['type' => 'const', 'value' => '0'],
            ]],
        ] as $index => $item) {
            PayrollSchemeItem::query()->create([
                'scheme_id' => (int) $scheme->id,
                'payroll_item_id' => null,
                'item_code' => $item['code'],
                'label' => $item['code'],
                'category' => 'EARNING',
                'calculation' => $item['calculation'],
                'amount_cents' => $item['amount'],
                'rate_scaled' => $item['rate'],
                'basis' => 'BASE_SALARY',
                'formula_json' => $item['formula'],
                'sort_order' => ($index + 1) * 10,
                'taxable' => true,
            ]);
        }

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $result = app(CalculationService::class)->calculate($run, 10);

        $this->assertSame(PayrollRunStatus::CALCULATED, $run->refresh()->status);
        $this->assertSame(1_148_000, $result['gross_cents']);
        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $items = PayslipLine::query()->where('payslip_id', $payslip->id)->where('category', 'EARNING')->pluck('amount_cents', 'item_code');
        $this->assertSame(38_000, (int) $items['MEAL']);
        $this->assertSame(50_000, (int) $items['PCT_BONUS']);
        $this->assertSame(10_000, (int) $items['ATTENDANCE_BONUS']);
        $this->assertSame(50_000, (int) $items['FORMULA_BONUS']);
    }

    public function test_invalid_formula_blocks_employee_instead_of_defaulting_to_zero(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            ['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560],
        ]);
        $scheme = $base['scheme'];
        PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $scheme->id,
            'payroll_item_id' => null,
            'item_code' => 'BAD_FORMULA',
            'label' => 'Bad formula',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => ['type' => 'var', 'name' => 'not_allowed'],
            'sort_order' => 10,
            'taxable' => true,
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        $this->assertSame(PayrollRunStatus::CALCULATED_WITH_BLOCKERS, $run->refresh()->status);
        $employee = PayrollRunEmployee::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(PayrollEmployeeStatus::FAILED, $employee->status);
        $this->assertStringContainsString('Variable is not allowed', (string) $employee->failure_reason);
    }
}

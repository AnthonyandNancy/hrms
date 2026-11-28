<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\TaxParameterResolver;
use Tests\Support\PayrollTestCase;

final class PayrollAcceptanceSampleTest extends PayrollTestCase
{
    public function test_ac07_exact_gross_deductions_net_and_employer_cost_sample(): void
    {
        $base = $this->seedPayrollBase(948_000);
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            ['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560],
        ]);
        $this->configureSyntheticZeroTaxAndInsurance();

        $scheme = $base['scheme'];
        PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $scheme->id,
            'payroll_item_id' => null,
            'item_code' => 'AC07_DEDUCTION',
            'label' => 'AC-07 deduction',
            'category' => 'DEDUCTION',
            'calculation' => 'FIXED',
            'amount_cents' => 140_000,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => null,
            'sort_order' => 10,
            'taxable' => false,
        ]);
        PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $scheme->id,
            'payroll_item_id' => null,
            'item_code' => 'AC07_EMPLOYER',
            'label' => 'AC-07 employer cost',
            'category' => 'EMPLOYER_COST',
            'calculation' => 'FIXED',
            'amount_cents' => 50_000,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => null,
            'sort_order' => 20,
            'taxable' => false,
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $result = app(CalculationService::class)->calculate($run, 10);

        $this->assertSame(948_000, $result['gross_cents']);
        $this->assertSame(140_000, $result['deductions_cents']);
        $this->assertSame(808_000, $result['net_cents']);
        $this->assertSame(998_000, $result['employer_cost_cents']);

        $stored = PayrollRun::query()->findOrFail($run->id);
        $this->assertSame(948_000, (int) $stored->gross_cents);
        $this->assertSame(140_000, (int) $stored->deductions_cents);
        $this->assertSame(808_000, (int) $stored->net_cents);
        $this->assertSame(998_000, (int) $stored->employer_cost_cents);

        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(808_000, (int) $payslip->net_cents);
        $this->assertSame(1, (int) PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->where('item_code', 'AC07_DEDUCTION')
            ->where('amount_cents', 140_000)
            ->count());
        $this->assertSame(1, (int) PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->where('item_code', 'AC07_EMPLOYER')
            ->where('amount_cents', 50_000)
            ->count());
    }

    private function configureSyntheticZeroTaxAndInsurance(): void
    {
        $parameters = app(TaxParameterResolver::class);
        $parameters->createVersion([
            'parameter_set' => 'TAX',
            'version' => 'AC07-TAX-2026',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'THRESHOLD_CENTS' => 1_000_000,
            'brackets' => [
                ['lower_bound_cents' => 0, 'upper_bound_cents' => null, 'rate_bps' => 0, 'quick_deduction_cents' => 0],
            ],
        ], 99, true, 'AC07-SYNTHETIC-NOT-LEGAL-ADVICE');

        foreach (['SOCIAL_INSURANCE', 'HOUSING_FUND'] as $set) {
            $parameters->createVersion([
                'parameter_set' => $set,
                'version' => 'AC07-'.$set.'-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'MIN_BASE_CENTS' => 400_000,
                'MAX_BASE_CENTS' => 3_000_000,
                'EMPLOYEE_RATE' => '0.000000',
                'EMPLOYER_RATE' => '0.000000',
            ], 99, true, 'AC07-SYNTHETIC-NOT-LEGAL-ADVICE');
        }
    }
}

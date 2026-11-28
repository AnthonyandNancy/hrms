<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

final class PayrollTravelFactTest extends PayrollTestCase
{
    public function test_closed_travel_minutes_are_paid_and_visible_to_formula_rules(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->closeTimeFact($employmentId, '2026-01', [
            [
                'business_date' => '2026-01-01',
                'scheduled_minutes' => 10_560,
                'worked_minutes' => 0,
                'travel_minutes' => 10_560,
            ],
        ]);

        $scheme = $base['scheme'];
        PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $scheme->id,
            'payroll_item_id' => null,
            'item_code' => 'TRAVEL_BONUS',
            'label' => 'Travel attendance bonus',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => [
                'type' => 'if',
                'condition' => [
                    'type' => 'compare',
                    'op' => 'GTE',
                    'left' => ['type' => 'var', 'name' => 'travel_minutes'],
                    'right' => ['type' => 'const', 'value' => '480'],
                ],
                'then' => ['type' => 'const', 'value' => '10000'],
                'else' => ['type' => 'const', 'value' => '0'],
            ],
            'sort_order' => 10,
            'taxable' => true,
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $result = app(CalculationService::class)->calculate($run, 10);

        $this->assertSame(PayrollRunStatus::CALCULATED, $run->refresh()->status);
        $this->assertSame(1_010_000, $result['gross_cents']);
        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(1_010_000, (int) $payslip->gross_cents);
        $this->assertSame(10_000, (int) PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->where('item_code', 'TRAVEL_BONUS')
            ->value('amount_cents'));
    }
}

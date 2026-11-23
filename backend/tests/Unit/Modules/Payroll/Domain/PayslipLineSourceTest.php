<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\EarningLine;
use App\Modules\Payroll\Domain\ManualAdjustment;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\PayslipLineCategory;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class PayslipLineSourceTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_every_generated_line_has_mandatory_source_and_increasing_sort_order(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(60),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            allowances: [
                new EarningLine('MEAL', 'Meal allowance', Money::fromMinor(100_000), new PayrollLineSource('scheme_item', '101'), 20),
            ],
            bonuses: [
                new EarningLine('BONUS', 'One-time bonus', Money::fromMinor(50_000), new PayrollLineSource('approved_bonus', '2026-01:42'), 30),
            ],
            specialDeductions: [
                new DeductionLine('SPECIAL', 'Special deduction', Money::fromMinor(100_000), new PayrollLineSource('deduction_declaration', 'D1'), 90),
            ],
            otherDeductions: [
                new DeductionLine('UNION', 'Union fee', Money::fromMinor(1_000), new PayrollLineSource('deduction_instruction', 'U1'), 91),
            ],
            manualAdjustments: [
                new ManualAdjustment('SUPPLEMENT', 'Supplement', Money::fromMinor(20_000), new PayrollLineSource('payroll_adjustment', 'A1'), 80),
                new ManualAdjustment('RECOVERY', 'Recovery', Money::fromMinor(-10_000), new PayrollLineSource('payroll_adjustment', 'A2'), 92),
            ],
            socialInsurance: new \App\Modules\Payroll\Domain\SocialInsuranceRule(
                version: 'DEMO-SI-v1',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('8'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('16'),
            ),
            housingFund: new \App\Modules\Payroll\Domain\HousingFundRule(
                version: 'DEMO-HF-v1',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('7'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('7'),
            ),
        ));

        $this->assertNotEmpty($result->lines);
        $previousSort = -1;
        foreach ($result->lines as $line) {
            $this->assertNotSame('', trim($line->source->type));
            $this->assertNotSame('', trim($line->source->id));
            $this->assertGreaterThan($previousSort, $line->sortOrder);
            $previousSort = $line->sortOrder;
        }
    }

    public function test_totals_equal_the_sum_of_their_respective_lines(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(60),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            allowances: [
                new EarningLine('MEAL', 'Meal allowance', Money::fromMinor(100_000), new PayrollLineSource('scheme_item', '101'), 20),
            ],
            bonuses: [
                new EarningLine('BONUS', 'One-time bonus', Money::fromMinor(50_000), new PayrollLineSource('approved_bonus', 'B1'), 30),
            ],
            otherDeductions: [
                new DeductionLine('UNION', 'Union fee', Money::fromMinor(1_000), new PayrollLineSource('deduction_instruction', 'U1'), 91),
            ],
            socialInsurance: new \App\Modules\Payroll\Domain\SocialInsuranceRule(
                version: 'DEMO-SI-v1',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('8'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('16'),
            ),
            housingFund: new \App\Modules\Payroll\Domain\HousingFundRule(
                version: 'DEMO-HF-v1',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('7'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('7'),
            ),
        ));

        $earnings = $this->sumCategory($result->lines, PayslipLineCategory::EARNING);
        $deductions = $this->sumCategory($result->lines, PayslipLineCategory::DEDUCTION);
        $employer = $this->sumCategory($result->lines, PayslipLineCategory::EMPLOYER_COST);

        $this->assertSame($result->gross->minor(), $earnings);
        $this->assertSame($result->employeeDeductions->minor(), $deductions);
        $this->assertSame($result->gross->minor() - $deductions, $result->net->minor());
        $this->assertSame($result->employerSocialInsurance->minor() + $result->employerHousingFund->minor(), $employer);
        $this->assertSame($result->gross->minor() + $employer, $result->employerCost->minor());
    }

    public function test_duplicate_sort_orders_cannot_be_emitted_by_calculator_inputs(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            allowances: [
                new EarningLine('MEAL', 'Meal', Money::fromMinor(100), new PayrollLineSource('scheme_item', '1'), 20),
                new EarningLine('PHONE', 'Phone', Money::fromMinor(100), new PayrollLineSource('scheme_item', '2'), 20),
            ],
        ));

        $sortOrders = array_map(static fn ($line): int => $line->sortOrder, $result->lines);
        $this->assertSame($sortOrders, array_values(array_unique($sortOrders)));
    }

    /**
     * @param  list<\App\Modules\Payroll\Domain\PayslipLineDraft>  $lines
     */
    private function sumCategory(array $lines, PayslipLineCategory $category): int
    {
        $total = 0;
        foreach ($lines as $line) {
            if ($line->category === $category) {
                $total += $line->amount->minor();
            }
        }

        return $total;
    }
}

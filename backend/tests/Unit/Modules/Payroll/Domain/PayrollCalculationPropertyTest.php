<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\EarningLine;
use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\ManualAdjustment;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\PayslipLineCategory;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Modules\Payroll\Domain\YtdTaxSnapshot;
use App\Shared\Money\Money;
use App\Shared\Money\Percentage;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic property-style coverage. The random generator is explicitly
 * seeded so every run produces the same 500 arithmetic cases.
 */
final class PayrollCalculationPropertyTest extends TestCase
{
    use PayrollTestFixtures;

    private const CASES = 500;

    private const SEED = 20261123;

    public function test_five_hundred_seeded_cases_preserve_all_money_invariants(): void
    {
        mt_srand(self::SEED);
        $calculator = new PayrollCalculator;
        $executed = 0;

        for ($case = 0; $case < self::CASES; $case++) {
            $year = mt_rand(2025, 2028);
            $month = mt_rand(1, 12);
            $salary = mt_rand(0, 5_000_000);
            $scheduled = mt_rand(0, 12_000);
            $unpaid = $scheduled > 0 ? mt_rand(0, $scheduled) : 0;
            $remaining = $scheduled - $unpaid;
            $paidLeave = $remaining > 0 ? mt_rand(0, $remaining) : 0;
            $worked = $remaining - $paidLeave;
            $overtime = mt_rand(0, 2_400);
            $allowance = mt_rand(0, 500_000);
            $bonus = mt_rand(0, 300_000);
            $adjustment = mt_rand(-100_000, 100_000);
            $other = mt_rand(0, 100_000);
            $special = mt_rand(0, 400_000);
            $ytdTaxable = mt_rand(0, 3_000_000);
            $ytdWithheld = mt_rand(0, 300_000);
            $siRate = sprintf('%d.%06d', mt_rand(0, 20), mt_rand(0, 999_999));
            $hfRate = sprintf('%d.%06d', mt_rand(0, 12), mt_rand(0, 999_999));
            $overtimeMultiplier = sprintf('%d.%06d', mt_rand(0, 3), mt_rand(0, 999_999));

            $input = $this->makeInput(
                baseSalaryCents: $salary,
                attendance: new \App\Modules\Payroll\Domain\AttendanceSummary(
                    scheduledMinutes: $scheduled,
                    workedMinutes: $worked,
                    paidLeaveMinutes: $paidLeave,
                    unpaidLeaveMinutes: $unpaid,
                    overtimeMinutes: $overtime,
                ),
                period: new PayrollPeriod($year, $month),
                context: $this->context(
                    divisor: $case % 2 === 0
                        ? ProrationDivisor::ANNUAL_DAYS_365
                        : ProrationDivisor::FIXED_21_75,
                    allowNegativeNet: true,
                ),
                allowances: $allowance > 0 ? [
                    new EarningLine('ALLOWANCE', 'Allowance', Money::fromMinor($allowance), new PayrollLineSource('scheme_item', 'A'), 20),
                ] : [],
                bonuses: $bonus > 0 ? [
                    new EarningLine('BONUS', 'Bonus', Money::fromMinor($bonus), new PayrollLineSource('approved_bonus', 'B'), 30),
                ] : [],
                manualAdjustments: $adjustment !== 0 ? [
                    new ManualAdjustment('ADJUSTMENT', 'Adjustment', Money::fromMinor($adjustment), new PayrollLineSource('payroll_adjustment', 'C'), 40),
                ] : [],
                specialDeductions: $special > 0 ? [
                    new DeductionLine('SPECIAL', 'Special', Money::fromMinor($special), new PayrollLineSource('deduction_declaration', 'D'), 900),
                ] : [],
                otherDeductions: $other > 0 ? [
                    new DeductionLine('OTHER', 'Other', Money::fromMinor($other), new PayrollLineSource('deduction_instruction', 'E'), 910),
                ] : [],
                ytd: new YtdTaxSnapshot($year, $ytdTaxable, $ytdWithheld),
                socialInsurance: new SocialInsuranceRule(
                    version: 'DEMO-SI-property',
                    minBaseCents: 300_000,
                    maxBaseCents: 3_000_000,
                    employeeRate: Percentage::fromPercent($siRate),
                    employerRate: Percentage::fromPercent($siRate),
                ),
                housingFund: new HousingFundRule(
                    version: 'DEMO-HF-property',
                    minBaseCents: 200_000,
                    maxBaseCents: 2_500_000,
                    employeeRate: Percentage::fromPercent($hfRate),
                    employerRate: Percentage::fromPercent($hfRate),
                ),
                overtimeMultiplier: \App\Shared\Money\Decimal::fromString($overtimeMultiplier),
            );

            $result = $calculator->calculate($input);

            $this->assertSame(
                $result->gross->minor() - $result->employeeDeductions->minor(),
                $result->net->minor(),
                "Case {$case}: net must equal gross minus all deductions.",
            );
            $this->assertSame(
                $result->gross->minor() + $result->employerSocialInsurance->minor() + $result->employerHousingFund->minor(),
                $result->employerCost->minor(),
                "Case {$case}: employer cost formula must hold.",
            );
            $this->assertSame(
                $result->employeeSocialInsurance->minor()
                    + $result->employeeHousingFund->minor()
                    + $result->tax->minor()
                    + $result->otherDeductions->minor(),
                $result->employeeDeductions->minor(),
                "Case {$case}: deduction components must reconcile.",
            );

            $earningTotal = 0;
            $deductionTotal = 0;
            $employerTotal = 0;
            foreach ($result->lines as $line) {
                $this->assertNotSame('', $line->source->type);
                $this->assertNotSame('', $line->source->id);
                $this->assertGreaterThanOrEqual(0, $line->amount->minor());
                $this->assertNotSame('-0.00', $line->amount->toDecimalString());

                if ($line->category === PayslipLineCategory::EARNING) {
                    $earningTotal += $line->amount->minor();
                } elseif ($line->category === PayslipLineCategory::DEDUCTION) {
                    $deductionTotal += $line->amount->minor();
                } else {
                    $employerTotal += $line->amount->minor();
                }
            }

            $this->assertSame($result->gross->minor(), $earningTotal);
            $this->assertSame($result->employeeDeductions->minor(), $deductionTotal);
            $this->assertSame(
                $result->employerSocialInsurance->minor() + $result->employerHousingFund->minor(),
                $employerTotal,
            );

            $executed++;
        }

        $this->assertSame(self::CASES, $executed);
    }

    public function test_generated_cases_are_deterministic_for_the_same_seed(): void
    {
        $first = $this->signatureForSeed();
        $second = $this->signatureForSeed();

        $this->assertSame($first, $second);
    }

    /**
     * @return array{int, int, int}
     */
    private function signatureForSeed(): array
    {
        mt_srand(self::SEED);
        $salary = mt_rand(0, 5_000_000);
        $minutes = mt_rand(0, 12_000);
        $adjustment = mt_rand(-100_000, 100_000);

        return [$salary, $minutes, $adjustment];
    }
}

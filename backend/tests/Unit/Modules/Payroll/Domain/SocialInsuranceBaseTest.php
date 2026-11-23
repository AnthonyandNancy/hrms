<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;
use PHPUnit\Framework\TestCase;

final class SocialInsuranceBaseTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_base_below_minimum_is_clamped_up_for_employee_and_employer(): void
    {
        $result = (new PayrollCalculator)->calculate($this->inputFor(500000, $this->siRule(600000, 3000000, '10', '20')));

        $this->assertSame(60000, $result->employeeSocialInsurance->minor());
        $this->assertSame(120000, $result->employerSocialInsurance->minor());
    }

    public function test_base_within_range_uses_gross(): void
    {
        $result = (new PayrollCalculator)->calculate($this->inputFor(1000000, $this->siRule(600000, 3000000, '10', '20')));

        $this->assertSame(100000, $result->employeeSocialInsurance->minor());
        $this->assertSame(200000, $result->employerSocialInsurance->minor());
    }

    public function test_base_above_maximum_is_clamped_down(): void
    {
        $result = (new PayrollCalculator)->calculate($this->inputFor(4000000, $this->siRule(600000, 3000000, '10', '20')));

        $this->assertSame(300000, $result->employeeSocialInsurance->minor());
        $this->assertSame(600000, $result->employerSocialInsurance->minor());
    }

    public function test_rate_change_is_parameter_driven(): void
    {
        $first = (new PayrollCalculator)->calculate($this->inputFor(1000000, $this->siRule(0, 10000000, '10', '10')));
        $second = (new PayrollCalculator)->calculate($this->inputFor(1000000, $this->siRule(0, 10000000, '12', '12')));

        $this->assertSame(100000, $first->employeeSocialInsurance->minor());
        $this->assertSame(120000, $second->employeeSocialInsurance->minor());
    }

    public function test_housing_fund_has_an_independent_base_clamp(): void
    {
        $context = $this->context();
        $input = $this->makeInput(
            baseSalaryCents: 500000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $context,
            taxTable: $this->noTaxTable(),
            socialInsurance: $this->zeroSocialInsurance(),
            housingFund: new HousingFundRule(
                version: 'DEMO-HF-v1',
                minBaseCents: 800000,
                maxBaseCents: 2000000,
                employeeRate: Percentage::fromPercent('12'),
                employerRate: Percentage::fromPercent('12'),
            ),
        );

        $result = (new PayrollCalculator)->calculate($input);

        $this->assertSame(96000, $result->employeeHousingFund->minor());
        $this->assertSame(96000, $result->employerHousingFund->minor());
    }

    public function test_rounding_mode_changes_contribution_at_the_cent_boundary(): void
    {
        $rule = new SocialInsuranceRule(
            version: 'DEMO-SI-rate',
            minBaseCents: 0,
            maxBaseCents: 10000000,
            employeeRate: Percentage::fromPercent('10.005'),
            employerRate: Percentage::fromPercent('0'),
        );

        $halfUp = (new PayrollCalculator)->calculate(
            $this->inputFor(99950, $rule, RoundingMode::HALF_UP),
        );
        $halfEven = (new PayrollCalculator)->calculate(
            $this->inputFor(99950, $rule, RoundingMode::HALF_EVEN),
        );

        $this->assertSame(10000, $halfUp->employeeSocialInsurance->minor());
        $this->assertSame(10000, $halfEven->employeeSocialInsurance->minor());
    }

    private function inputFor(int $grossCents, SocialInsuranceRule $rule, RoundingMode $mode = RoundingMode::HALF_UP): \App\Modules\Payroll\Domain\PayrollCalculationInput
    {
        return $this->makeInput(
            baseSalaryCents: $grossCents,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context($mode, ProrationDivisor::ANNUAL_DAYS_365),
            taxTable: $this->noTaxTable(),
            socialInsurance: $rule,
            housingFund: $this->zeroHousingFund(),
        );
    }

    private function siRule(int $min, int $max, string $employeeRate, string $employerRate): SocialInsuranceRule
    {
        return new SocialInsuranceRule(
            version: 'DEMO-SI-v1',
            minBaseCents: $min,
            maxBaseCents: $max,
            employeeRate: Percentage::fromPercent($employeeRate),
            employerRate: Percentage::fromPercent($employerRate),
        );
    }
}

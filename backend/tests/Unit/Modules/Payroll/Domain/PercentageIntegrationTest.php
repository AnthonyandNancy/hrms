<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Shared\Money\Percentage;
use PHPUnit\Framework\TestCase;

final class PercentageIntegrationTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_fractional_percentage_rate_is_applied_as_a_ratio_not_as_raw_percent(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            taxTable: $this->noTaxTable(),
            socialInsurance: new SocialInsuranceRule(
                version: 'DEMO-SI-fraction',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: Percentage::fromPercent('8.5'),
                employerRate: Percentage::fromPercent('16.25'),
            ),
            housingFund: new HousingFundRule(
                version: 'DEMO-HF-fraction',
                minBaseCents: 0,
                maxBaseCents: 10_000_000,
                employeeRate: Percentage::fromPercent('7.125'),
                employerRate: Percentage::fromPercent('7.125'),
            ),
        ));

        $this->assertSame(85_000, $result->employeeSocialInsurance->minor());
        $this->assertSame(162_500, $result->employerSocialInsurance->minor());
        $this->assertSame(71_250, $result->employeeHousingFund->minor());
        $this->assertSame(71_250, $result->employerHousingFund->minor());
    }
}

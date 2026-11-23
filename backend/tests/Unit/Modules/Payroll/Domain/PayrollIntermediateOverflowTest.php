<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\YtdTaxSnapshot;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollIntermediateOverflowTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_cumulative_taxable_addition_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: PHP_INT_MAX,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            ytd: new YtdTaxSnapshot(2026, 1, 0),
            taxTable: $this->standardTaxTable(),
            socialInsurance: new \App\Modules\Payroll\Domain\SocialInsuranceRule(
                version: 'DEMO-SI-overflow',
                minBaseCents: 0,
                maxBaseCents: PHP_INT_MAX,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('100'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('0'),
            ),
            housingFund: new \App\Modules\Payroll\Domain\HousingFundRule(
                version: 'DEMO-HF-overflow',
                minBaseCents: 0,
                maxBaseCents: PHP_INT_MAX,
                employeeRate: \App\Shared\Money\Percentage::fromPercent('0'),
                employerRate: \App\Shared\Money\Percentage::fromPercent('0'),
            ),
        ));
    }

    public function test_other_deduction_summation_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 0,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            taxTable: $this->noTaxTable(),
            otherDeductions: [
                new DeductionLine(
                    'A',
                    'A',
                    Money::fromMinor(PHP_INT_MAX),
                    new PayrollLineSource('deduction_instruction', 'A'),
                    900,
                ),
                new DeductionLine(
                    'B',
                    'B',
                    Money::fromMinor(PHP_INT_MAX),
                    new PayrollLineSource('deduction_instruction', 'B'),
                    910,
                ),
            ],
        ));
    }
}

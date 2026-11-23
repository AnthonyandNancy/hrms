<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\EarningLine;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollOverflowTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_gross_aggregation_rejects_native_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: PHP_INT_MAX,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(allowNegativeNet: true),
            taxTable: $this->noTaxTable(),
            allowances: [
                new EarningLine('ALLOWANCE', 'Allowance', Money::fromMinor(PHP_INT_MAX), new PayrollLineSource('scheme_item', '1'), 20),
            ],
        ));
    }

    public function test_zero_scheduled_minutes_with_zero_salary_is_zero_not_a_division_error(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 0,
            attendance: new \App\Modules\Payroll\Domain\AttendanceSummary(0, 0, 0, 0, 0),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            taxTable: $this->noTaxTable(),
        ));

        $this->assertSame(0, $result->gross->minor());
        $this->assertSame('0.00', $result->gross->toDecimalString());
    }
}

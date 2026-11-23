<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Shared\Money\Decimal;
use PHPUnit\Framework\TestCase;

final class ProrationTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_full_attendance_pays_the_full_monthly_salary(): void
    {
        $result = $this->calculate(3650000, $this->fullAttendance(), ProrationDivisor::ANNUAL_DAYS_365);

        $this->assertSame(3650000, $result->gross->minor());
        $this->assertSame(1, count($result->lines));
    }

    public function test_mid_month_join_deducts_unworked_days_with_annual_divisor(): void
    {
        $result = $this->calculate(
            3650000,
            new AttendanceSummary(10440, 5220, 0, 0, 0),
            ProrationDivisor::ANNUAL_DAYS_365,
        );

        // Daily rate is 36,500 * 12 / 365 = 1,200 CNY; 10.875 unworked days.
        $this->assertSame(2345000, $result->gross->minor());
    }

    public function test_mid_month_join_uses_fixed_21_75_divisor(): void
    {
        $result = $this->calculate(
            2175000,
            new AttendanceSummary(10440, 5220, 0, 0, 0),
            ProrationDivisor::FIXED_21_75,
        );

        // Daily rate is 21,750 / 21.75 = 1,000 CNY; 10.875 unworked days.
        $this->assertSame(1087500, $result->gross->minor());
    }

    public function test_paid_leave_remains_paid(): void
    {
        $result = $this->calculate(
            3650000,
            new AttendanceSummary(10440, 5220, 5220, 0, 0),
            ProrationDivisor::ANNUAL_DAYS_365,
        );

        $this->assertSame(3650000, $result->gross->minor());
    }

    public function test_explicit_unpaid_leave_reduces_pay_by_the_daily_rate(): void
    {
        $result = $this->calculate(
            3650000,
            new AttendanceSummary(10440, 9960, 0, 480, 0),
            ProrationDivisor::ANNUAL_DAYS_365,
        );

        $this->assertSame(3530000, $result->gross->minor());
    }

    public function test_overtime_uses_exact_minutes_and_a_parameterised_multiplier(): void
    {
        $result = $this->calculate(
            3650000,
            $this->fullAttendance(120),
            ProrationDivisor::ANNUAL_DAYS_365,
        );

        // Daily rate 1,200 CNY / 8 hours * 2 hours * 1.5 = 450 CNY.
        $this->assertSame(3695000, $result->gross->minor());
    }

    public function test_leap_year_february_has_29_days_and_still_pays_full_salary(): void
    {
        $period = new PayrollPeriod(2028, 2);
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 3650000,
            attendance: $this->fullAttendance(),
            period: $period,
            context: $this->context(),
            taxTable: $this->noTaxTable(),
            overtimeMultiplier: Decimal::fromString('1.5'),
        ));

        $this->assertSame(29, $period->daysInMonth());
        $this->assertSame('2028-02-01', $period->start()->format('Y-m-d'));
        $this->assertSame('2028-02-29', $period->end()->format('Y-m-d'));
        $this->assertSame(3650000, $result->gross->minor());
    }

    private function calculate(
        int $salary,
        AttendanceSummary $attendance,
        ProrationDivisor $divisor,
    ): \App\Modules\Payroll\Domain\PayrollCalculationResult {
        return (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: $salary,
            attendance: $attendance,
            period: new PayrollPeriod(2026, 1),
            context: $this->context(divisor: $divisor),
            taxTable: $this->noTaxTable(),
        ));
    }
}

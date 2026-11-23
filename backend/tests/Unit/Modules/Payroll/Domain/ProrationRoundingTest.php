<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Shared\Money\RoundingMode;
use PHPUnit\Framework\TestCase;

final class ProrationRoundingTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_proration_rounds_only_once_at_the_salary_line_boundary(): void
    {
        // One cent monthly salary, all 21.75 standard days unworked.
        // Annual-divisor daily rate is 0.000328 CNY (scale 6); the absence
        // deduction leaves 0.002866 CNY = 0.2866 cent. HALF_UP must apply once
        // at the salary line boundary and produce zero.
        $result = $this->calculate(
            baseSalaryCents: 1,
            attendance: new AttendanceSummary(10440, 0, 0, 10440, 0),
            mode: RoundingMode::HALF_UP,
        );

        $this->assertSame(0, $result->gross->minor());
        $this->assertSame('0.00', $result->gross->toDecimalString());
    }

    public function test_proration_down_mode_truncates_a_fractional_cent(): void
    {
        $input = $this->makeInput(
            baseSalaryCents: 1,
            attendance: new AttendanceSummary(10440, 0, 0, 10440, 0),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(RoundingMode::DOWN, ProrationDivisor::ANNUAL_DAYS_365),
            taxTable: $this->noTaxTable(),
        );

        $result = (new PayrollCalculator)->calculate($input);

        // The fractional cent is truncated by DOWN.
        $this->assertSame(0, $result->gross->minor());
    }

    public function test_proration_up_mode_rounds_a_fractional_cent_to_one_cent(): void
    {
        // Same sub-cent result as above: UP moves the salary line to one cent.
        $input = $this->makeInput(
            baseSalaryCents: 1,
            attendance: new AttendanceSummary(10440, 0, 0, 10440, 0),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(RoundingMode::UP, ProrationDivisor::ANNUAL_DAYS_365),
            taxTable: $this->noTaxTable(),
        );

        $result = (new PayrollCalculator)->calculate($input);

        $this->assertSame(1, $result->gross->minor());
    }

    private function calculate(
        int $baseSalaryCents,
        AttendanceSummary $attendance,
        RoundingMode $mode,
    ): \App\Modules\Payroll\Domain\PayrollCalculationResult {
        return (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: $baseSalaryCents,
            attendance: $attendance,
            period: new PayrollPeriod(2026, 1),
            context: $this->context($mode, ProrationDivisor::ANNUAL_DAYS_365),
            taxTable: $this->noTaxTable(),
        ));
    }
}

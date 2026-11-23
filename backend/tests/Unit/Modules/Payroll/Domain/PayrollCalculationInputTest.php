<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\EarningLine;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollCalculationInputTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_rejects_an_input_line_in_a_different_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            allowances: [
                new EarningLine(
                    'MEAL',
                    'Meal',
                    Money::fromMinor(10_000, 'USD'),
                    new PayrollLineSource('scheme_item', '1'),
                    20,
                ),
            ],
        );
    }

    public function test_rejects_an_unexpected_element_type_in_a_line_collection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            /** @phpstan-ignore argument.type */
            allowances: [new \stdClass],
        );
    }

    public function test_keeps_the_four_payroll_time_axes_separate(): void
    {
        $input = $this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
        );

        $this->assertSame('2026-01', $input->period->label());
        $this->assertSame('2026-01', $input->postingPeriod->label());
        $this->assertSame('2026-02-05', $input->payDate->format('Y-m-d'));
        $this->assertSame('2026-01-31 18:00:00', $input->cutoffAt->format('Y-m-d H:i:s'));
    }
}

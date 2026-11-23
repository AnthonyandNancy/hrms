<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class NegativeZeroTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_zero_result_has_no_negative_zero_anywhere(): void
    {
        $result = (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: 0,
            attendance: new \App\Modules\Payroll\Domain\AttendanceSummary(0, 0, 0, 0, 0),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(),
            taxTable: $this->noTaxTable(),
        ));

        $this->assertSame(0, $result->gross->minor());
        $this->assertSame(0, $result->employeeDeductions->minor());
        $this->assertSame(0, $result->net->minor());
        $this->assertSame('0.00', $result->gross->toDecimalString());
        $this->assertSame('0.00', $result->net->toDecimalString());
        $this->assertStringNotContainsString('-0', json_encode($result->gross, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('-0', json_encode($result->net, JSON_THROW_ON_ERROR));
        foreach ($result->lines as $line) {
            $this->assertSame(0, $line->amount->minor());
            $this->assertSame('0.00', $line->amount->toDecimalString());
        }
    }

    public function test_money_and_rounded_decimals_never_serialize_minus_zero(): void
    {
        $values = [
            Money::fromDecimalString('-0.0001'),
            Money::fromDecimalString('-0.0049'),
            Money::fromMinor(-1)->add(Money::fromMinor(1)),
        ];

        foreach ($values as $money) {
            $this->assertSame(0, $money->minor());
            $this->assertSame('0.00', $money->toDecimalString());
            $this->assertStringNotContainsString('-0', json_encode($money, JSON_THROW_ON_ERROR));
        }
    }
}

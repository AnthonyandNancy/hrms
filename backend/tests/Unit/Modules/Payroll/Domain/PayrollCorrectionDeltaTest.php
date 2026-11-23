<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollCorrectionDelta;
use App\Modules\Payroll\Domain\PayrollPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollCorrectionDeltaTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_zero_correction_is_zero(): void
    {
        $original = $this->calculate(1_000_000);
        $delta = PayrollCorrectionDelta::between($original, $this->calculate(1_000_000));

        $this->assertTrue($delta->isZero());
        $this->assertSame(0, $delta->gross->minor());
        $this->assertSame('0.00', $delta->net->toDecimalString());
    }

    public function test_recovery_can_produce_negative_deltas(): void
    {
        $original = $this->calculate(1_100_000);
        $revised = $this->calculate(1_000_000);
        $delta = PayrollCorrectionDelta::between($original, $revised);

        $this->assertSame(-100_000, $delta->gross->minor());
        $this->assertTrue($delta->net->isNegative());
        $this->assertSame($revised->net->minor(), $original->net->add($delta->net)->minor());
    }

    public function test_delta_currencies_must_match(): void
    {
        $original = $this->calculate(1_000_000);
        $revised = $this->calculate(
            1_000_000,
            currency: 'USD',
        );

        $this->expectException(InvalidArgumentException::class);

        PayrollCorrectionDelta::between($original, $revised);
    }

    private function calculate(int $salary, string $currency = 'CNY'): \App\Modules\Payroll\Domain\PayrollCalculationResult
    {
        $context = $this->context();
        if ($currency !== 'CNY') {
            $context = new \App\Modules\Payroll\Domain\PayrollContext(
                legalEntityId: 1,
                currency: $currency,
                timezone: 'Asia/Shanghai',
                rounding: new \App\Modules\Payroll\Domain\RoundingConfig(\App\Shared\Money\RoundingMode::HALF_UP),
                prorationDivisor: \App\Modules\Payroll\Domain\ProrationDivisor::ANNUAL_DAYS_365,
            );
        }

        return (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: $salary,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $context,
            taxTable: $this->noTaxTable(),
        ));
    }
}

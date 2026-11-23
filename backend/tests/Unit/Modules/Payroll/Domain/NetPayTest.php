<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\PayrollCalculationInput;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NetPayTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_negative_net_is_rejected_unless_explicitly_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate($this->inputWithNet(-100));
    }

    public function test_negative_net_can_be_returned_when_explicitly_allowed(): void
    {
        $result = (new PayrollCalculator)->calculate($this->inputWithNet(-100, allowNegativeNet: true));

        $this->assertSame(-100, $result->net->minor());
        $this->assertSame('-1.00', $result->net->toDecimalString());
        $this->assertContains('NET_BELOW_ZERO', $result->warnings);
        $this->assertSame($result->gross->minor() - $result->employeeDeductions->minor(), $result->net->minor());
    }

    public function test_exact_zero_net_never_emits_negative_zero(): void
    {
        $result = (new PayrollCalculator)->calculate($this->inputWithNet(0));

        $this->assertSame(0, $result->net->minor());
        $this->assertSame('0.00', $result->net->toDecimalString());
        $this->assertSame([], $result->warnings);
    }

    private function inputWithNet(int $netCents, bool $allowNegativeNet = false): PayrollCalculationInput
    {
        // Gross 100 cents, other deduction 200 cents -> net -100.
        // For zero net, deduction is 100 cents.
        $deductionCents = $netCents < 0 ? 200 : 100;

        return $this->makeInput(
            baseSalaryCents: 100,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2026, 1),
            context: $this->context(allowNegativeNet: $allowNegativeNet),
            taxTable: $this->noTaxTable(),
            otherDeductions: [
                new DeductionLine(
                    'OTHER',
                    'Other',
                    Money::fromMinor($deductionCents),
                    new PayrollLineSource('deduction_instruction', 'D1'),
                    900,
                ),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\ManualAdjustment;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayslipLineCategory;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ManualAdjustmentAmountTest extends TestCase
{
    public function test_negative_adjustment_absolute_amount_is_exact_and_becomes_deduction(): void
    {
        $adjustment = new ManualAdjustment(
            'RECOVERY',
            'Recovery',
            Money::fromMinor(-12345),
            new PayrollLineSource('payroll_adjustment', 'A1'),
            10,
        );

        $this->assertSame(12345, $adjustment->absoluteAmount()->minor());
        $this->assertSame(PayslipLineCategory::DEDUCTION, $adjustment->toPayslipLine()->category);
    }

    public function test_php_int_min_recovery_is_rejected_because_its_absolute_value_is_not_representable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ManualAdjustment(
            'RECOVERY',
            'Recovery',
            Money::fromMinor(PHP_INT_MIN),
            new PayrollLineSource('payroll_adjustment', 'A1'),
            10,
        ))->absoluteAmount();
    }
}

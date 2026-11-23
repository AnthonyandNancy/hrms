<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Money\Decimal;
use App\Shared\Money\Percentage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PercentageTest extends TestCase
{
    public function test_percent_and_ratio_round_trip_at_six_decimals(): void
    {
        $percentage = Percentage::fromPercent('12.345600');

        $this->assertSame(123456, $percentage->scaled());
        $this->assertSame('12.345600', $percentage->toRatio()->multiply(Decimal::fromString('100'))->toDecimalString());
        $this->assertSame(500000, Percentage::fromRatio(Decimal::fromString('0.5'))->scaled());
    }

    public function test_percentage_rejects_negative_inputs(): void
    {
        foreach ([
            fn () => Percentage::fromPercent('-0.01'),
            fn () => Percentage::fromScaledInt(-1),
            fn () => Percentage::fromRatio(Decimal::fromString('-0.1')),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Negative percentage should be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_one_hundred_percent_is_exactly_one(): void
    {
        $percentage = Percentage::fromPercent('100');

        $this->assertTrue($percentage->toRatio()->equals(Decimal::fromString('1')));
        $this->assertSame(1000000, $percentage->scaled());
    }
}

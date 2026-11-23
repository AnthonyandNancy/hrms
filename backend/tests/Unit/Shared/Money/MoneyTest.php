<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Money\Decimal;
use App\Shared\Money\Money;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_add_and_subtract_keep_integer_minor_units(): void
    {
        $money = Money::fromMinor(123456, 'CNY');

        $this->assertSame(123556, $money->add(Money::fromMinor(100))->minor());
        $this->assertSame(123356, $money->subtract(Money::fromMinor(100))->minor());
        $this->assertSame('1234.56', $money->toDecimalString());
    }

    public function test_addition_rejects_native_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(PHP_INT_MAX)->add(Money::fromMinor(1));
    }

    public function test_subtraction_rejects_native_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(PHP_INT_MIN)->subtract(Money::fromMinor(1));
    }

    public function test_absolute_is_exact_and_rejects_unrepresentable_int_min(): void
    {
        $this->assertSame(12345, Money::fromMinor(-12345)->absolute()->minor());
        $this->assertSame(0, Money::fromMinor(0)->absolute()->minor());

        $this->expectException(InvalidArgumentException::class);
        Money::fromMinor(PHP_INT_MIN)->absolute();
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CNY')->add(Money::fromMinor(100, 'USD'));
    }

    public function test_allocation_distributes_every_cent_and_stays_deterministic(): void
    {
        $parts = Money::fromMinor(1001)->allocate(3);

        $this->assertSame([334, 334, 333], array_map(static fn (Money $part): int => $part->minor(), $parts));
        $this->assertSame(1001, array_sum(array_map(static fn (Money $part): int => $part->minor(), $parts)));

        $negative = Money::fromMinor(-1001)->allocate(3);
        $this->assertSame([-334, -334, -333], array_map(static fn (Money $part): int => $part->minor(), $negative));
        $this->assertSame(-1001, array_sum(array_map(static fn (Money $part): int => $part->minor(), $negative)));
    }

    public function test_allocation_requires_positive_part_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100)->allocate(0);
    }

    public function test_multiply_by_decimal_ratio_rounds_once_at_the_money_boundary(): void
    {
        $this->assertSame(15000, Money::fromMinor(10000)->multiplyByRatio(Decimal::fromString('1.5'))->minor());
        $this->assertSame(3, Money::fromMinor(5)->multiplyByRatio(Decimal::fromString('0.5'))->minor());
        $this->assertSame(2, Money::fromMinor(5)->multiplyByRatio(Decimal::fromString('0.5'), RoundingMode::DOWN)->minor());
        $this->assertSame(14, Money::fromMinor(10)->multiplyByRatio(Percentage::fromPercent('135'))->minor());
    }

    public function test_money_can_be_created_from_decimal_string_at_stored_scale(): void
    {
        $this->assertSame(12345, Money::fromDecimalString('123.45')->minor());
        $this->assertSame(-3, Money::fromDecimalString('-0.025')->minor());
        $this->assertSame(0, Money::fromDecimalString('-0.004')->minor());
    }

    public function test_json_has_stable_shape_and_decimal_amount(): void
    {
        $money = Money::fromMinor(123456, 'CNY');

        $this->assertSame(
            ['amount' => '1234.56', 'currency' => 'CNY', 'minor' => 123456],
            $money->jsonSerialize(),
        );
        $this->assertSame(
            '{"amount":"-0.05","currency":"CNY","minor":-5}',
            json_encode(Money::fromMinor(-5), JSON_THROW_ON_ERROR),
        );
    }

    public function test_zero_has_single_representation(): void
    {
        $zero = Money::fromMinor(-5)->add(Money::fromMinor(5));

        $this->assertTrue($zero->isZero());
        $this->assertSame('0.00', $zero->toDecimalString());
        $this->assertSame(0, $zero->minor());
    }
}

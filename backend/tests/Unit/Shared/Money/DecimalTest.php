<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_add_and_subtract_are_exact_and_preserve_result_scale(): void
    {
        $left = Decimal::fromString('123456789012345678.123456');
        $right = Decimal::fromString('0.876544');

        $this->assertSame('123456789012345679.000000', $left->add($right)->toDecimalString());
        $this->assertSame('123456789012345677.246912', $left->subtract($right)->toDecimalString());
    }

    public function test_multiply_and_divide_are_exact(): void
    {
        $this->assertSame('12.375', Decimal::fromString('1.5')->multiply(Decimal::fromString('8.25'))->toDecimalString());
        $this->assertSame('0.333333', Decimal::fromString('1')->divide(Decimal::fromString('3'), 6)->toDecimalString());
        $this->assertSame('0.666667', Decimal::fromString('2')->divide(Decimal::fromString('3'), 6)->toDecimalString());
        $this->assertSame('-0.125000', Decimal::fromString('-1')->divide(Decimal::fromString('8'), 6)->toDecimalString());
    }

    public function test_division_uses_configured_rounding_mode_and_sticky_remainder(): void
    {
        $down = Decimal::fromString('2')->divide(Decimal::fromString('3'), 2, RoundingMode::DOWN);
        $up = Decimal::fromString('2')->divide(Decimal::fromString('3'), 2, RoundingMode::UP);
        $even = Decimal::fromString('1')->divide(Decimal::fromString('2'), 0, RoundingMode::HALF_EVEN);

        $this->assertSame('0.66', $down->toDecimalString());
        $this->assertSame('0.67', $up->toDecimalString());
        $this->assertSame('0', $even->toDecimalString());
    }

    public function test_compare_is_numeric_not_lexicographic(): void
    {
        $this->assertSame(1, Decimal::fromString('10.0')->compare(Decimal::fromString('9.999999')));
        $this->assertSame(-1, Decimal::fromString('-2')->compare(Decimal::fromString('-1.999999')));
        $this->assertSame(0, Decimal::fromString('1.2300')->compare(Decimal::fromString('1.23')));
        $this->assertTrue(Decimal::fromString('1.2300')->equals(Decimal::fromString('1.23')));
    }

    public function test_scaled_integer_round_trip(): void
    {
        $value = Decimal::fromScaledInt(-987654321, 6);

        $this->assertSame('-987.654321', $value->toDecimalString());
        $this->assertSame(-987654321, $value->toScaledInt(6));
        $this->assertSame(-988, $value->roundTo(0, RoundingMode::HALF_UP)->toScaledInt(0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDecimals(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'scientific notation' => ['1e6'];
        yield 'two decimal points' => ['1.2.3'];
        yield 'bare sign' => ['-'];
        yield 'bare decimal point' => ['.'];
        yield 'too many integer digits' => ['1234567890123456789'];
        yield 'too many integer digits with fraction' => ['1234567890123456789.1'];
        yield 'whitespace' => [' 1.00'];
    }

    #[DataProvider('invalidDecimals')]
    public function test_rejects_invalid_or_out_of_range_input(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::fromString($value);
    }

    public function test_rejects_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::fromString('1')->divide(Decimal::fromString('0'), 6);
    }

    public function test_decimal_string_normalizes_signed_zero_and_leading_zeroes(): void
    {
        $this->assertSame('0.000', Decimal::fromString('-0.000')->toDecimalString());
        $this->assertSame('1.50', Decimal::fromString('0001.5000')->roundTo(2, RoundingMode::HALF_UP)->toDecimalString());
        $this->assertFalse(str_starts_with(Decimal::fromString('-0')->negate()->toDecimalString(), '-'));
    }

    public function test_large_supported_value_can_be_manipulated_exactly(): void
    {
        $value = Decimal::fromString('999999999999999999.999999');

        $this->assertSame('1000000000000000000.000000', $value->add(Decimal::fromString('0.000001'))->toDecimalString());
        $this->assertSame(999999999999999999, $value->toScaledInt(0, RoundingMode::DOWN));
    }
}

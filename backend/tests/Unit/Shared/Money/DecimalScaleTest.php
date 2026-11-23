<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalScaleTest extends TestCase
{
    /**
     * @return iterable<string, array{RoundingMode, string, string}>
     */
    public static function tieModes(): iterable
    {
        yield 'half up keeps sign on exact half' => [RoundingMode::HALF_UP, '-2.5', '-3'];
        yield 'half even negative exact half to even' => [RoundingMode::HALF_EVEN, '-2.5', '-2'];
        yield 'half even negative odd up to even' => [RoundingMode::HALF_EVEN, '-3.5', '-4'];
        yield 'down never rounds away from zero on negative tie' => [RoundingMode::DOWN, '-2.9', '-2'];
        yield 'up always rounds away from zero on negative remainder' => [RoundingMode::UP, '-2.1', '-3'];
    }

    #[DataProvider('tieModes')]
    public function test_negative_tie_and_remainder_modes_exactly(RoundingMode $mode, string $input, string $expected): void
    {
        $actual = $mode->round(Decimal::fromString($input), 0);

        $this->assertSame($expected, $actual->toDecimalString());
    }

    public function test_division_into_negative_divisor_preserves_sign(): void
    {
        $this->assertSame('0.500000', Decimal::fromString('-1')->divide(Decimal::fromString('-2'), 6)->toDecimalString());
        $this->assertSame('-0.500000', Decimal::fromString('1')->divide(Decimal::fromString('-2'), 6)->toDecimalString());
    }

    public function test_zero_divided_by_negative_is_positive_zero(): void
    {
        $result = Decimal::fromString('0')->divide(Decimal::fromString('-2'), 6);

        $this->assertTrue($result->isZero());
        $this->assertSame('0.000000', $result->toDecimalString());
    }

    public function test_scale_must_be_non_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::fromString('1')->roundTo(-1, RoundingMode::HALF_UP);
    }

    public function test_division_scale_must_be_non_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::fromString('1')->divide(Decimal::fromString('2'), -1);
    }

    public function test_scaled_integer_scale_must_be_non_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::fromScaledInt(1, -1);
    }

    public function test_to_scaled_int_rounds_with_requested_mode(): void
    {
        $this->assertSame(1, Decimal::fromString('0.5')->toScaledInt(0, RoundingMode::HALF_UP));
        $this->assertSame(0, Decimal::fromString('0.5')->toScaledInt(0, RoundingMode::HALF_EVEN));
        $this->assertSame(-1, Decimal::fromString('-0.5')->toScaledInt(0, RoundingMode::HALF_UP));
        $this->assertSame(0, Decimal::fromString('-0.5')->toScaledInt(0, RoundingMode::HALF_EVEN));
    }
}

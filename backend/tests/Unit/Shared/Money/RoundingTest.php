<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RoundingMode, int, string}>
     */
    public static function roundingCases(): iterable
    {
        yield 'half-up positive tie' => ['1.005', RoundingMode::HALF_UP, 2, '1.01'];
        yield 'half-up negative tie rounds away from zero' => ['-1.005', RoundingMode::HALF_UP, 2, '-1.01'];
        yield 'half-up just below tie' => ['1.004999', RoundingMode::HALF_UP, 2, '1.00'];
        yield 'half-up above tie' => ['1.005001', RoundingMode::HALF_UP, 2, '1.01'];
        yield 'half-even positive down to even' => ['1.005', RoundingMode::HALF_EVEN, 2, '1.00'];
        yield 'half-even positive up to even' => ['1.015', RoundingMode::HALF_EVEN, 2, '1.02'];
        yield 'half-even negative down to even' => ['-1.005', RoundingMode::HALF_EVEN, 2, '-1.00'];
        yield 'half-even negative up to even' => ['-1.015', RoundingMode::HALF_EVEN, 2, '-1.02'];
        yield 'half-even above tie' => ['1.0051', RoundingMode::HALF_EVEN, 2, '1.01'];
        yield 'down positive truncates' => ['1.009', RoundingMode::DOWN, 2, '1.00'];
        yield 'down negative truncates toward zero' => ['-1.009', RoundingMode::DOWN, 2, '-1.00'];
        yield 'up positive moves away from zero' => ['1.001', RoundingMode::UP, 2, '1.01'];
        yield 'up negative moves away from zero' => ['-1.001', RoundingMode::UP, 2, '-1.01'];
        yield 'up exact amount does not move' => ['2.500', RoundingMode::UP, 2, '2.50'];
        yield 'half-up one decimal place' => ['12.45', RoundingMode::HALF_UP, 1, '12.5'];
        yield 'half-even one decimal place' => ['12.45', RoundingMode::HALF_EVEN, 1, '12.4'];
    }

    #[DataProvider('roundingCases')]
    public function test_rounds_exactly_at_requested_scale(string $value, RoundingMode $mode, int $scale, string $expected): void
    {
        $actual = $mode->round(Decimal::fromString($value), $scale);

        $this->assertSame($expected, $actual->toDecimalString());
    }

    public function test_rounding_that_produces_zero_never_emits_negative_zero(): void
    {
        foreach (['-0.004', '-0.0000001', '-0.4'] as $value) {
            $actual = RoundingMode::HALF_UP->round(Decimal::fromString($value), $value === '-0.4' ? 0 : 2);

            $this->assertSame('0'.($value === '-0.4' ? '' : '.00'), $actual->toDecimalString());
            $this->assertFalse(str_starts_with($actual->toDecimalString(), '-'));
            $this->assertTrue($actual->isZero());
        }
    }

    public function test_rounding_to_a_larger_scale_pads_with_zeroes(): void
    {
        $value = RoundingMode::HALF_UP->round(Decimal::fromString('12.3'), 6);

        $this->assertSame('12.300000', $value->toDecimalString());
    }
}

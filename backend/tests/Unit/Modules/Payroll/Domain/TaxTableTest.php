<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\TaxBracket;
use App\Modules\Payroll\Domain\TaxTable;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TaxTableTest extends TestCase
{
    private function table(): TaxTable
    {
        return new TaxTable(
            version: 'DEMO-TAX-2026-v1',
            thresholdCents: 500000,
            brackets: [
                new TaxBracket(
                    lowerCents: 0,
                    upperCents: 300000,
                    rate: Percentage::fromPercent('3'),
                    quickDeductionCents: 0,
                ),
                new TaxBracket(
                    lowerCents: 300000,
                    upperCents: null,
                    rate: Percentage::fromPercent('10'),
                    quickDeductionCents: 21000,
                ),
            ],
        );
    }

    public function test_zero_income_has_zero_tax(): void
    {
        $this->assertSame(0, $this->table()->taxOn(0));
    }

    public function test_bracket_boundaries_are_contiguous_and_use_quick_deduction(): void
    {
        $table = $this->table();

        $this->assertSame(9000, $table->taxOn(300000));
        $this->assertSame(9000, $table->taxOn(300001));
        $this->assertSame(19000, $table->taxOn(400000));
    }

    public function test_bracket_rounding_uses_requested_mode_at_the_cent(): void
    {
        $table = $this->table();

        $this->assertSame(9001, $table->taxOn(300005, RoundingMode::HALF_UP));
        $this->assertSame(9000, $table->taxOn(300005, RoundingMode::HALF_EVEN));
        $this->assertSame(9000, $table->taxOn(300009, RoundingMode::DOWN));
        $this->assertSame(9001, $table->taxOn(300001, RoundingMode::UP));
    }

    public function test_overlapping_brackets_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxTable(
            version: 'DEMO-BAD',
            thresholdCents: 0,
            brackets: [
                new TaxBracket(0, 300000, Percentage::fromPercent('3'), 0),
                new TaxBracket(200000, null, Percentage::fromPercent('10'), 0),
            ],
        );
    }

    public function test_gap_between_brackets_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxTable(
            version: 'DEMO-BAD',
            thresholdCents: 0,
            brackets: [
                new TaxBracket(0, 100000, Percentage::fromPercent('3'), 0),
                new TaxBracket(200000, null, Percentage::fromPercent('10'), 0),
            ],
        );
    }

    public function test_table_must_start_at_zero_and_have_an_open_top_bracket(): void
    {
        foreach ([
            [new TaxBracket(100, 200000, Percentage::fromPercent('3'), 0)],
            [new TaxBracket(0, 200000, Percentage::fromPercent('3'), 0)],
            [],
        ] as $brackets) {
            try {
                new TaxTable('DEMO-BAD', 0, $brackets);
                $this->fail('Invalid tax table should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_negative_quick_deduction_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxBracket(0, null, Percentage::fromPercent('10'), -1);
    }
}

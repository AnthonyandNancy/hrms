<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Shared\Time\BusinessDate;
use App\Shared\Time\DateRange;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function test_half_open_range_contains_start_but_not_end(): void
    {
        $range = new DateRange('2026-01-01', '2026-02-01');

        $this->assertTrue($range->contains('2026-01-01'));
        $this->assertTrue($range->contains('2026-01-31'));
        $this->assertFalse($range->contains('2026-02-01'));
    }

    public function test_open_ended_range_contains_all_future_business_dates(): void
    {
        $range = DateRange::openEnded('2026-01-01');

        $this->assertTrue($range->isOpenEnded());
        $this->assertTrue($range->contains('9999-12-30'));
        $this->assertFalse($range->contains('9999-12-31'));
    }

    public function test_overlap_respects_half_open_boundaries(): void
    {
        $first = new DateRange('2026-01-01', '2026-02-01');
        $touching = new DateRange('2026-02-01', '2026-03-01');
        $overlapping = new DateRange('2026-01-31', '2026-02-02');

        $this->assertFalse($first->overlaps($touching));
        $this->assertTrue($first->overlaps($overlapping));
    }

    public function test_a_range_must_end_after_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateRange('2026-02-01', '2026-02-01');
    }

    public function test_it_can_clip_a_range_to_a_business_date(): void
    {
        $range = new DateRange('2026-01-01', BusinessDate::OPEN_END);

        $this->assertSame('2026-02-01', $range->endingAt('2026-02-01')->toArray()['valid_to']);
    }
}

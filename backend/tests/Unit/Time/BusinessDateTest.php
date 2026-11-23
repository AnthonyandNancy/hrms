<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Shared\Time\BusinessDate;
use App\Shared\Time\FrozenClock;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BusinessDateTest extends TestCase
{
    public function test_it_accepts_a_real_calendar_date(): void
    {
        $date = BusinessDate::from('2026-11-23');

        $this->assertSame('2026-11-23', $date->value());
        $this->assertSame('2026-11-23', (string) $date);
    }

    public function test_it_rejects_impossible_calendar_dates(): void
    {
        foreach (['2026-02-29', '2026-13-01', '2026-00-10', '2026-11-31', '2026-1-1'] as $value) {
            $this->assertNull(BusinessDate::tryFrom($value), $value);
        }
    }

    public function test_today_is_derived_from_the_tenant_timezone_and_injected_clock(): void
    {
        $clock = new FrozenClock('2026-11-23T16:30:00Z');

        $this->assertSame('2026-11-24', BusinessDate::today('Asia/Shanghai', $clock)->value());
        $this->assertSame('2026-11-23', BusinessDate::today('UTC', $clock)->value());
    }

    public function test_it_compares_dates_as_business_dates(): void
    {
        $earlier = BusinessDate::from('2026-11-23');
        $later = BusinessDate::from('2026-11-24');

        $this->assertTrue($earlier->isBefore($later));
        $this->assertTrue($later->isAfter($earlier));
        $this->assertSame(0, $earlier->compareTo(BusinessDate::from('2026-11-23')));
    }

    public function test_open_end_is_the_documented_constant(): void
    {
        $this->assertSame('9999-12-31', BusinessDate::OPEN_END);
        $this->assertSame(BusinessDate::OPEN_END, BusinessDate::openEnd()->value());
        $this->assertTrue(BusinessDate::openEnd()->isOpenEnd());
        $this->assertSame('UTC', (new DateTimeZone('UTC'))->getName());
    }
}

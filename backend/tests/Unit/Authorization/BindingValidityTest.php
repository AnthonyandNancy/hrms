<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\BindingValidity;
use PHPUnit\Framework\TestCase;

final class BindingValidityTest extends TestCase
{
    public function test_a_grant_created_after_midnight_is_effective_the_same_day(): void
    {
        $this->assertTrue(BindingValidity::isActiveOn(
            '2026-11-25 00:42:38', '2027-11-26 00:42:38', '2026-11-25',
        ));
    }

    public function test_a_grant_expiring_at_day_start_is_not_effective_that_day(): void
    {
        $this->assertFalse(BindingValidity::isActiveOn(
            '2026-11-01 09:00:00', '2026-11-25 00:00:00', '2026-11-25',
        ));
    }

    public function test_a_grant_starting_late_in_the_day_is_effective_that_day(): void
    {
        $this->assertTrue(BindingValidity::isActiveOn(
            '2026-11-25 23:59:59', '2026-11-30 00:00:00', '2026-11-25',
        ));
    }

    public function test_a_grant_valid_through_yesterday_is_not_effective_today(): void
    {
        $this->assertFalse(BindingValidity::isActiveOn(
            '2026-11-01 00:00:00', '2026-11-25 00:00:00', '2026-11-26',
        ));
    }

    public function test_a_grant_starting_tomorrow_is_not_effective_today(): void
    {
        $this->assertFalse(BindingValidity::isActiveOn(
            '2026-11-26 00:00:00', '2027-11-26 00:00:00', '2026-11-25',
        ));
    }

    public function test_the_window_starts_at_the_business_day_boundaries(): void
    {
        $this->assertSame('2026-11-25 00:00:00', BindingValidity::dayStart('2026-11-25'));
        $this->assertSame('2026-11-26 00:00:00', BindingValidity::nextDayStart('2026-11-25'));
        $this->assertSame('2026-12-01 00:00:00', BindingValidity::nextDayStart('2026-11-30'));
        $this->assertSame('2027-01-01 00:00:00', BindingValidity::nextDayStart('2026-12-31'));
    }
}

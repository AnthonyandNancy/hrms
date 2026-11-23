<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\Services\StatutoryAnnualLeaveFloor;
use App\Shared\Exceptions\ValidationException;
use Tests\TestCase;

class StatutoryFloorTest extends TestCase
{
    public function test_floors_are_five_ten_and_fifteen_days_by_cumulative_service(): void
    {
        $rules = new StatutoryAnnualLeaveFloor;

        $this->assertSame(0, $rules->minimumDays('2025-11-24', '2026-11-23'));
        $this->assertSame(5, $rules->minimumDays('2025-11-23', '2026-11-23'));
        $this->assertSame(10, $rules->minimumDays('2016-11-23', '2026-11-23'));
        $this->assertSame(15, $rules->minimumDays('2006-11-23', '2026-11-23'));
    }

    public function test_one_day_short_of_ten_years_still_gets_five_days(): void
    {
        $this->assertSame(5, (new StatutoryAnnualLeaveFloor)->minimumDays('2016-11-24', '2026-11-23'));
    }

    public function test_statutory_entitlement_below_floor_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new StatutoryAnnualLeaveFloor)->assertEntitlementAtLeastFloor(
            isStatutory: true,
            configuredDays: '4.00',
            seniorityStartDate: '2000-01-01',
            asOf: '2026-11-23',
        );
    }

    public function test_company_leave_may_be_set_below_statutory_floor(): void
    {
        (new StatutoryAnnualLeaveFloor)->assertEntitlementAtLeastFloor(
            isStatutory: false,
            configuredDays: '1.00',
            seniorityStartDate: '2000-01-01',
            asOf: '2026-11-23',
        );

        $this->addToAssertionCount(1);
    }

    public function test_statutory_entitlement_at_floor_is_accepted(): void
    {
        $rules = new StatutoryAnnualLeaveFloor;
        $rules->assertEntitlementAtLeastFloor(true, '10.00', '2016-11-23', '2026-11-23');

        $this->assertSame(10, $rules->minimumDays('2016-11-23', '2026-11-23'));
    }
}

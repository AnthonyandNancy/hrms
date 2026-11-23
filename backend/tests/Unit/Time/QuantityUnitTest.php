<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\Services\QuantityUnit;
use App\Shared\Exceptions\ValidationException;
use Tests\TestCase;

class QuantityUnitTest extends TestCase
{
    public function test_day_quantity_is_stored_as_integer_minutes_using_standard_day(): void
    {
        $this->assertSame(480, QuantityUnit::fromDisplayDays('1', 480));
        $this->assertSame(240, QuantityUnit::fromDisplayDays('0.5', 480));
        $this->assertSame('1.00', QuantityUnit::toDisplayDays(480, 480));
        $this->assertSame('0.50', QuantityUnit::toDisplayDays(240, 480));
    }

    public function test_fraction_below_one_minute_is_rejected_not_rounded(): void
    {
        $this->expectException(ValidationException::class);

        QuantityUnit::fromDisplayDays('0.001', 480);
    }

    public function test_display_days_round_trip_for_quarter_day(): void
    {
        $minutes = QuantityUnit::fromDisplayDays('0.25', 480);

        $this->assertSame(120, $minutes);
        $this->assertSame('0.25', QuantityUnit::toDisplayDays($minutes, 480));
    }

    public function test_bad_standard_day_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        QuantityUnit::fromDisplayDays('1', 0);
    }
}

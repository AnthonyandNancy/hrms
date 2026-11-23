<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Converts between a shift's wall-clock window and absolute instants.
 * Overnight shifts own all punches from start through the next morning; the
 * shift start date is the business date. DST is handled by IANA timezone
 * arithmetic: elapsed worked minutes are real UTC minutes, so a repeated hour
 * is counted once and a skipped hour is absent.
 */
final class ShiftWindow
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function boundaries(
        string $timezone,
        string $businessDate,
        string $startLocalTime,
        string $endLocalTime,
        bool $crossesMidnight,
    ): array {
        $this->assertTimezone($timezone);
        $date = \App\Shared\Time\BusinessDate::parse($businessDate);
        $start = CarbonImmutable::parse($date->format('Y-m-d').' '.$startLocalTime, $timezone);
        $endDate = $crossesMidnight ? $date->addDay() : $date;
        $end = CarbonImmutable::parse($endDate->format('Y-m-d').' '.$endLocalTime, $timezone);

        if ($crossesMidnight) {
            if (strcmp($endLocalTime, $startLocalTime) >= 0 || $end->lessThanOrEqualTo($start)) {
                throw new ValidationException([
                    'end_local_time' => ['An overnight shift must have an end local time before its start local time.'],
                ]);
            }
        } elseif ($end->lessThan($start)) {
            throw new ValidationException([
                'end_local_time' => ['A non-overnight shift cannot end before it starts.'],
            ]);
        }

        return [$start, $end];
    }

    /**
     * Business date for a punch. Punches in the "morning tail" of an overnight
     * shift (from midnight through shift end) map to the previous day.
     */
    public function businessDate(
        string $timezone,
        string $startLocalTime,
        string $endLocalTime,
        bool $crossesMidnight,
        string $punchedAtUtc,
    ): string {
        $this->assertTimezone($timezone);
        $punch = CarbonImmutable::parse($punchedAtUtc, 'UTC');
        $local = $punch->setTimezone($timezone);

        if (! $crossesMidnight) {
            return $local->format('Y-m-d');
        }

        $localTime = $local->format('H:i:s');
        $end = strlen($endLocalTime) === 8 ? $endLocalTime : $endLocalTime.':00';

        return $localTime <= $end
            ? $local->subDay()->format('Y-m-d')
            : $local->format('Y-m-d');
    }

    /**
     * Real elapsed minutes between two instants. Carbon diffInMinutes on UTC
     * instants naturally handles spring-forward/fall-back exactly once.
     */
    public function elapsedMinutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        if ($end->lessThan($start)) {
            throw new ValidationException(['end' => ['End must not precede start.']]);
        }

        return (int) $start->utc()->diffInMinutes($end->utc(), true);
    }

    private function assertTimezone(string $timezone): void
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new ValidationException(['timezone' => ['The timezone must be a valid IANA timezone identifier.']]);
        }
    }
}

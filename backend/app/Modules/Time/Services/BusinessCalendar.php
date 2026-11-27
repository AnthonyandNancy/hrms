<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\LegalEntityCalendarDirectory;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Company business calendar projection. There is exactly one calendar row in
 * the single-company schema. A business date is always a date in that
 * calendar's IANA timezone; DATE(punched_at) is never used.
 */
final class BusinessCalendar implements LegalEntityCalendarDirectory
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  list<int>  $workweek
     */
    public function configure(
        string $timezone,
        array $workweek,
        int $standardDayMinutes = 480,
    ): LegalEntityCalendar {
        $this->assertTimezone($timezone);
        $this->assertWorkweek($workweek);

        if ($standardDayMinutes <= 0 || $standardDayMinutes > 1440) {
            throw new ValidationException([
                'standard_day_minutes' => ['The standard day must be between 1 and 1440 minutes.'],
            ]);
        }

        return LegalEntityCalendar::query()->updateOrCreate(
            ['id' => 1],
            [
                'timezone' => $timezone,
                'workweek_json' => array_values(array_map('intval', $workweek)),
                'standard_day_minutes' => $standardDayMinutes,
                'version' => 1,
                'updated_at' => $this->clock->now(),
                'created_at' => $this->clock->now(),
            ],
        );
    }

    public function calendar(): ?LegalEntityCalendar
    {
        return LegalEntityCalendar::query()->orderBy('id')->first();
    }

    public function timezone(): ?string
    {
        return $this->calendar()?->timezone;
    }

    /**
     * @return list<int>
     */
    public function workweek(): array
    {
        $calendar = $this->calendar();

        return $calendar === null ? [1, 2, 3, 4, 5] : array_values((array) $calendar->workweek_json);
    }

    public function standardDayMinutes(): int
    {
        return (int) ($this->calendar()->standard_day_minutes ?? 480);
    }

    public function addHoliday(
        string $date,
        string $name,
        string $type,
        ?string $source = null,
    ): Holiday {
        $this->assertDate($date);

        if (! in_array($type, ['PUBLIC', 'COMPANY', 'MAKEUP'], true)) {
            throw new ValidationException(['type' => ['Holiday type must be PUBLIC, COMPANY or MAKEUP.']]);
        }

        if ($this->calendar() === null) {
            throw new ConflictException('Configure the company calendar before adding holidays.');
        }

        return Holiday::query()->updateOrCreate(
            ['date' => $date],
            [
                'name' => $name,
                'type' => $type,
                'source' => $source,
                'version' => 1,
                'created_at' => $this->clock->now(),
            ],
        );
    }

    public function holiday(string $date): ?Holiday
    {
        return Holiday::query()->where('date', $date)->first();
    }

    /**
     * A MAKEUP day forces work even on a weekend; PUBLIC/COMPANY force rest
     * even on a weekday. Otherwise weekday membership decides.
     */
    public function isRestDay(string $date): bool
    {
        $this->assertDate($date);
        $holiday = $this->holiday($date);

        if ($holiday !== null) {
            return $holiday->type !== 'MAKEUP';
        }

        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

        return ! in_array($weekday, $this->workweek(), true);
    }

    public function isWorkingDay(string $date): bool
    {
        return ! $this->isRestDay($date);
    }

    public function assertTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new ValidationException(['timezone' => ['The timezone must be a valid IANA timezone identifier.']]);
        }
    }

    public function localDate(string $timezone, CarbonImmutable $utc): CarbonImmutable
    {
        $this->assertTimezone($timezone);

        return $utc->setTimezone($timezone)->startOfDay();
    }

    /**
     * @param  list<int>  $workweek
     */
    private function assertWorkweek(array $workweek): void
    {
        if ($workweek === []) {
            throw new ValidationException(['workweek_json' => ['At least one working weekday is required.']]);
        }

        foreach ($workweek as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                throw new ValidationException([
                    'workweek_json' => ['Each workweek value must be an ISO weekday integer 1-7.'],
                ]);
            }
        }
    }

    private function assertDate(string $date): void
    {
        BusinessDate::parse($date);
    }
}

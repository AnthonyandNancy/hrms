<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Business calendar projection. A business date is always a date in the legal
 * entity's IANA timezone; DATE(punched_at) is never used.
 */
final class BusinessCalendar
{
    public function __construct(
        private readonly \App\Shared\Time\Clock $clock,
    ) {}

    /**
     * @param  list<int>  $workweek
     */
    public function configure(
        int $tenantId,
        int $legalEntityId,
        string $timezone,
        array $workweek,
        int $standardDayMinutes = 480,
    ): \App\Modules\Time\Models\LegalEntityCalendar {
        $this->assertTimezone($timezone);
        $this->assertWorkweek($workweek);

        if ($standardDayMinutes <= 0 || $standardDayMinutes > 1440) {
            throw new ValidationException([
                'standard_day_minutes' => ['The standard day must be between 1 and 1440 minutes.'],
            ]);
        }

        return \App\Modules\Time\Models\LegalEntityCalendar::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
            ],
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

    public function calendar(int $tenantId, int $legalEntityId): ?\App\Modules\Time\Models\LegalEntityCalendar
    {
        return \App\Modules\Time\Models\LegalEntityCalendar::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->first();
    }

    public function timezone(int $tenantId, int $legalEntityId): ?string
    {
        return $this->calendar($tenantId, $legalEntityId)?->timezone;
    }

    /**
     * @return list<int>
     */
    public function workweek(int $tenantId, int $legalEntityId): array
    {
        $calendar = $this->calendar($tenantId, $legalEntityId);

        return $calendar === null ? [1, 2, 3, 4, 5] : array_values((array) $calendar->workweek_json);
    }

    public function standardDayMinutes(int $tenantId, int $legalEntityId): int
    {
        return (int) ($this->calendar($tenantId, $legalEntityId)->standard_day_minutes ?? 480);
    }

    public function addHoliday(
        int $tenantId,
        int $legalEntityId,
        string $date,
        string $name,
        string $type,
        ?string $source = null,
    ): \App\Modules\Time\Models\Holiday {
        $this->assertDate($date);

        if (! in_array($type, ['PUBLIC', 'COMPANY', 'MAKEUP'], true)) {
            throw new ValidationException(['type' => ['Holiday type must be PUBLIC, COMPANY or MAKEUP.']]);
        }

        if ($this->calendar($tenantId, $legalEntityId) === null) {
            throw new ConflictException('Configure a legal entity calendar before adding holidays.');
        }

        return \App\Modules\Time\Models\Holiday::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'date' => $date,
            ],
            [
                'name' => $name,
                'type' => $type,
                'source' => $source,
                'version' => 1,
                'created_at' => $this->clock->now(),
            ],
        );
    }

    public function holiday(int $tenantId, int $legalEntityId, string $date): ?\App\Modules\Time\Models\Holiday
    {
        return \App\Modules\Time\Models\Holiday::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('date', $date)
            ->first();
    }

    /**
     * A MAKEUP day forces work even on a weekend; PUBLIC/COMPANY force rest
     * even on a weekday. Otherwise weekday membership decides.
     */
    public function isRestDay(int $tenantId, int $legalEntityId, string $date): bool
    {
        $this->assertDate($date);
        $holiday = $this->holiday($tenantId, $legalEntityId, $date);

        if ($holiday !== null) {
            return $holiday->type !== 'MAKEUP';
        }

        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

        return ! in_array($weekday, $this->workweek($tenantId, $legalEntityId), true);
    }

    public function isWorkingDay(int $tenantId, int $legalEntityId, string $date): bool
    {
        return ! $this->isRestDay($tenantId, $legalEntityId, $date);
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
        \App\Shared\Time\BusinessDate::parse($date);
    }
}

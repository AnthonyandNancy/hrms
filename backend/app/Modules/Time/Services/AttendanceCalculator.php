<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Models\AttendanceCorrection;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\AttendanceRuleVersion;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Models\ShiftAssignment;
use App\Modules\Time\Models\ShiftTemplate;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AttendanceCalculator
{
    public const DEFAULT_RULE_VERSION = 'TIME-RULES-2026.1';

    /** @var array<string, mixed> */
    public const DEFAULT_RULE_CONFIG = [
        'late_grace_source' => 'SHIFT',
        'count_break' => true,
        'overtime_after_scheduled' => true,
        'incomplete_threshold_minutes' => 1,
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessCalendar $calendar,
        private readonly ShiftWindow $windows,
        private readonly EmploymentDirectory $employments,
    ) {}

    public function recalculate(int $employmentId, string $businessDate): AttendanceDay
    {
        BusinessDate::parse($businessDate);

        return DB::transaction(function () use ($employmentId, $businessDate): AttendanceDay {
            $snapshot = $this->employments->snapshot($employmentId, $businessDate);

            if ($snapshot === null) {
                throw new ConflictException('The employment is not active on the requested business date.');
            }

            $calendar = $this->calendar->calendar();
            $shift = $this->shiftAssignment($employmentId, $businessDate);
            $existing = AttendanceDay::query()
                ->where('employment_id', $employmentId)
                ->where('business_date', $businessDate)
                ->first();

            $ruleVersion = $existing->rule_version ?? $this->ruleVersionFor($businessDate);
            $config = $this->ruleConfig($ruleVersion);

            $isRest = ! $this->calendar->isWorkingDay($businessDate);
            $holiday = $this->calendar->holiday($businessDate);
            $punches = $this->effectivePunches($employmentId, $businessDate, $calendar, $shift);
            $scheduled = $this->scheduledMinutes($calendar, $shift, $businessDate);
            $break = $shift->break_minutes ?? 0;

            $worked = 0;
            $late = 0;
            $early = 0;
            $overtime = 0;
            $status = 'NORMAL';
            $sourceParts = [$ruleVersion, (string) $scheduled, (string) $break, $businessDate];

            if ($punches !== []) {
                usort($punches, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
                $first = $punches[0];
                $last = $punches[array_key_last($punches)];
                $worked = $this->windows->elapsedMinutes($first, $last);

                if ($shift !== null) {
                    $worked = max(0, $worked - $break);
                    [$start, $end] = $this->windows->boundaries(
                        $calendar->timezone ?? 'UTC',
                        $businessDate,
                        $shift->start_local_time,
                        $shift->end_local_time,
                        (bool) $shift->crosses_midnight,
                    );
                    $late = $first->greaterThan($start)
                        ? max(0, $this->windows->elapsedMinutes($start, $first) - $shift->late_grace_minutes)
                        : 0;
                    $early = $last->lessThan($end)
                        ? max(0, $this->windows->elapsedMinutes($last, $end) - $shift->early_grace_minutes)
                        : 0;

                    if ($config['overtime_after_scheduled'] === true) {
                        $overtime = max(0, $worked - $scheduled);
                    }

                    if ($late > 0 || $early > 0) {
                        $status = $late > 0 ? 'LATE' : 'EARLY';
                    }
                } else {
                    $status = 'NORMAL';
                }

                $sourceParts = [...$sourceParts, ...array_map(
                    static fn (CarbonImmutable $punch): string => $punch->format('Y-m-d\TH:i:s.uP'),
                    $punches,
                )];
            } else {
                $worked = 0;
                $sourceParts[] = 'NO_PUNCHES';

                if ($isRest) {
                    $status = $holiday !== null ? 'HOLIDAY' : 'REST';
                } else {
                    $status = 'ABSENT';
                }
            }

            if (! $isRest && $scheduled > 0 && $worked > 0 && $worked < $scheduled) {
                $status = $late > 0 || $early > 0 ? 'LATE' : 'INCOMPLETE';
            }

            $absence = $isRest ? 0 : max(0, $scheduled - $worked);
            $hash = hash('sha256', implode('|', $sourceParts));

            if ($existing !== null && hash_equals($existing->source_hash, $hash)) {
                return $existing;
            }

            $day = $existing ?? new AttendanceDay([
                'employment_id' => $employmentId,
                'business_date' => $businessDate,
            ]);

            $day->forceFill([
                'employment_id' => $employmentId,
                'business_date' => $businessDate,
                'shift_template_id' => $shift?->id,
                'scheduled_minutes' => $scheduled,
                'worked_minutes' => $worked,
                'overtime_minutes' => $overtime,
                'late_minutes' => $late,
                'early_leave_minutes' => $early,
                'absence_minutes' => $absence,
                'status' => $status,
                'rule_version' => $ruleVersion,
                'source_hash' => $hash,
                'calculated_at' => $this->clock->now(),
            ])->save();

            return $day->refresh();
        });
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function effectivePunches(
        int $employmentId,
        string $businessDate,
        ?LegalEntityCalendar $calendar,
        ?ShiftTemplate $shift,
    ): array {
        $timezone = $calendar->timezone ?? 'UTC';
        $center = CarbonImmutable::parse($businessDate.' 12:00:00', $timezone);
        $from = $center->subDays(2)->utc();
        $to = $center->addDays(2)->utc();

        $raw = PunchRecord::query()
            ->where('employment_id', $employmentId)
            ->where('punched_at_utc', '>=', $from)
            ->where('punched_at_utc', '<', $to)
            ->orderBy('punched_at_utc')
            ->get();

        $correction = AttendanceCorrection::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', $businessDate)
            ->whereNotNull('applied_at')
            ->latest('applied_at')
            ->first();

        $candidates = [];

        if ($correction !== null) {
            foreach ((array) $correction->corrected_punches_json as $value) {
                if (is_string($value)) {
                    $candidates[] = $value;
                } elseif (is_array($value) && is_string($value['punched_at_utc'] ?? null)) {
                    $candidates[] = $value['punched_at_utc'];
                }
            }
        } else {
            foreach ($raw as $punch) {
                $candidates[] = $punch->punched_at_utc->toIso8601ZuluString();
            }
        }

        $result = [];

        foreach ($candidates as $candidate) {
            $instant = CarbonImmutable::parse($candidate, 'UTC')->utc();

            if ($shift === null) {
                if ($instant->setTimezone($timezone)->format('Y-m-d') !== $businessDate) {
                    continue;
                }
            } else {
                $belongs = $this->windows->businessDate(
                    $timezone,
                    $shift->start_local_time,
                    $shift->end_local_time,
                    (bool) $shift->crosses_midnight,
                    $instant->toIso8601ZuluString(),
                );

                if ($belongs !== $businessDate) {
                    continue;
                }
            }

            $result[] = $instant;
        }

        return $result;
    }

    private function scheduledMinutes(?LegalEntityCalendar $calendar, ?ShiftTemplate $shift, string $businessDate): int
    {
        if ($shift === null) {
            return 0;
        }

        $timezone = $calendar->timezone ?? 'UTC';
        [$start, $end] = $this->windows->boundaries(
            $timezone,
            $businessDate,
            $shift->start_local_time,
            $shift->end_local_time,
            (bool) $shift->crosses_midnight,
        );

        return max(0, $this->windows->elapsedMinutes($start, $end) - $shift->break_minutes);
    }

    public function shiftAssignment(int $employmentId, string $businessDate): ?ShiftTemplate
    {
        $assignment = ShiftAssignment::query()
            ->where('employment_id', $employmentId)
            ->where('valid_from', '<=', $businessDate)
            ->where('valid_to', '>', $businessDate)
            ->orderByDesc('valid_from')
            ->first();

        if ($assignment === null) {
            return null;
        }

        return ShiftTemplate::query()
            ->whereKey($assignment->shift_template_id)
            ->where('valid_from', '<=', $businessDate)
            ->where('valid_to', '>', $businessDate)
            ->first();
    }

    public function ruleVersionFor(string $businessDate): string
    {
        $version = AttendanceRuleVersion::query()
            ->where('effective_from', '<=', $businessDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $version->version ?? self::DEFAULT_RULE_VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleConfig(string $version): array
    {
        $row = AttendanceRuleVersion::query()
            ->where('version', $version)
            ->first();

        return $row === null ? self::DEFAULT_RULE_CONFIG : (array) $row->config_json;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function registerRuleVersion(string $version, string $effectiveFrom, array $config): AttendanceRuleVersion
    {
        BusinessDate::parse($effectiveFrom);

        return AttendanceRuleVersion::query()->updateOrCreate(
            ['version' => $version],
            [
                'config_json' => $config,
                'effective_from' => $effectiveFrom,
                'checksum' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'created_at' => $this->clock->now(),
            ],
        );
    }
}

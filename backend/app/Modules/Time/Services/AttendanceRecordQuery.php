<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Models\ShiftTemplate;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only attendance detail projection.
 *
 * Row scope is part of the SQL query before count/pagination/export. The
 * browser never supplies a trusted employment scope: SELF is pinned to the
 * session employment, MANAGED is pinned to the resolved managed ids and
 * COMPANY/HR/ADMIN may read every employment in the single company.
 *
 * Raw punches remain evidence. This service only SELECTs them and never emits
 * the payload hash. first_in/last_out/source are derived from the persisted
 * punch rows that belong to the attendance business date.
 */
final class AttendanceRecordQuery
{
    /**
     * The export contract is intentionally narrow. No punch hash, local
     * offset, raw payload, employee id or monetary field is exported.
     *
     * @var list<string>
     */
    public const EXPORT_FIELDS = [
        'employee_no',
        'employee_name',
        'business_date',
        'status',
        'first_in',
        'last_out',
        'late_minutes',
        'early_leave_minutes',
        'absence_minutes',
    ];

    public function __construct(
        private readonly EmploymentDirectory $employments,
        private readonly BusinessCalendar $calendar,
        private readonly ShiftWindow $windows,
    ) {}

    /**
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function records(TimeActor $actor, array $filters, int $page, int $pageSize): array
    {
        $query = $this->attendanceQuery($actor, $filters);
        $total = (int) (clone $query)->count();

        /** @var list<AttendanceDay> $rows */
        $rows = $query
            ->orderBy('business_date')
            ->orderBy('employment_id')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        return [
            'items' => $this->payloads($rows),
            'total' => $total,
        ];
    }

    /**
     * Full result set for a CSV export. The controller reuses the same scope
     * and field whitelist; this method never paginates because an export is a
     * snapshot of the authorised filter, not the current screen page.
     *
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     * @return list<list<string>>
     */
    public function exportRows(TimeActor $actor, array $filters): array
    {
        $query = $this->attendanceQuery($actor, $filters);

        /** @var list<AttendanceDay> $rows */
        $rows = $query
            ->orderBy('business_date')
            ->orderBy('employment_id')
            ->get()
            ->all();

        return array_map(
            static fn (array $row): array => [
                (string) $row['employee_no'],
                (string) $row['employee_name'],
                (string) $row['business_date'],
                (string) $row['status'],
                $row['first_in'] === null ? '' : (string) $row['first_in'],
                $row['last_out'] === null ? '' : (string) $row['last_out'],
                (string) $row['late_minutes'],
                (string) $row['early_leave_minutes'],
                (string) $row['absence_minutes'],
            ],
            $this->payloads($rows),
        );
    }

    /**
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function punches(TimeActor $actor, array $filters, int $page, int $pageSize): array
    {
        $query = $this->punchQuery($actor, $filters);
        $total = (int) (clone $query)->count();

        /** @var list<PunchRecord> $rows */
        $rows = $query
            ->orderByDesc('punched_at_utc')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        return [
            'items' => array_map(static fn (PunchRecord $row): array => [
                'id' => (int) $row->id,
                'employment_id' => (int) $row->employment_id,
                'punched_at_utc' => $row->punched_at_utc->toIso8601ZuluString(),
                'source' => (string) $row->source,
                'device_id' => $row->device_id !== null ? (string) $row->device_id : null,
            ], $rows),
            'total' => $total,
        ];
    }

    public function timezone(): string
    {
        return $this->calendar->timezone()
            ?? (string) config('hrms.default_timezone', 'Asia/Shanghai');
    }

    /**
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     * @return Builder<AttendanceDay>
     */
    private function attendanceQuery(TimeActor $actor, array $filters): Builder
    {
        $query = AttendanceDay::query();
        $this->applyEmploymentScope($query, $actor);

        $employmentId = $filters['employment_id'] ?? null;

        if ($employmentId !== null) {
            $this->assertEmploymentInScope($actor, (int) $employmentId, (string) $filters['from']);
            $query->where('employment_id', (int) $employmentId);
        }

        $query->where('business_date', '>=', (string) $filters['from'])
            ->where('business_date', '<=', (string) $filters['to']);

        return $query;
    }

    /**
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     * @return Builder<PunchRecord>
     */
    private function punchQuery(TimeActor $actor, array $filters): Builder
    {
        $query = PunchRecord::query();
        $this->applyEmploymentScope($query, $actor);

        $employmentId = $filters['employment_id'] ?? null;

        if ($employmentId !== null) {
            $this->assertEmploymentInScope($actor, (int) $employmentId, (string) $filters['from']);
            $query->where('employment_id', (int) $employmentId);
        }

        $timezone = $this->timezone();
        $fromUtc = CarbonImmutable::parse((string) $filters['from'].' 00:00:00', $timezone)->utc();
        $toExclusive = CarbonImmutable::parse((string) $filters['to'].' 00:00:00', $timezone)->addDay()->utc();

        return $query
            ->where('punched_at_utc', '>=', $fromUtc)
            ->where('punched_at_utc', '<', $toExclusive);
    }

    /**
     * Keep this predicate identical in spirit to AttendanceOpsService: an
     * actor's explicit scope, never a client-provided scope flag.
     *
     * @param  Builder<AttendanceDay|PunchRecord>  $query
     */
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyEmploymentScope(Builder $query, TimeActor $actor): void
    {
        $scope = strtoupper($actor->scope);

        if ($scope === 'SELF') {
            $query->where('employment_id', $actor->employmentId ?? -1);

            return;
        }

        if ($scope === 'MANAGED') {
            $query->whereIn(
                'employment_id',
                $actor->managedEmploymentIds === [] ? [-1] : $actor->managedEmploymentIds,
            );
        }
    }

    private function assertEmploymentInScope(TimeActor $actor, int $employmentId, string $businessDate): void
    {
        if (! $actor->canReadEmployment($employmentId)) {
            throw new NotFoundOrForbiddenException;
        }

        // An unknown/inactive employment must not be distinguishable from an
        // out-of-scope one. This mirrors GET /v1/time/attendance/{id}.
        if ($this->employments->snapshot($employmentId, $businessDate) === null) {
            throw new NotFoundOrForbiddenException;
        }
    }

    /**
     * @param  list<AttendanceDay>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloads(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $timezone = $this->timezone();
        $templateIds = array_values(array_unique(array_filter(
            array_map(static fn (AttendanceDay $row): ?int => $row->shift_template_id !== null ? (int) $row->shift_template_id : null, $rows),
            static fn (?int $id): bool => $id !== null,
        )));

        /** @var array<int, ShiftTemplate> $templates */
        $templates = $templateIds === []
            ? []
            : ShiftTemplate::query()
                ->whereIn('id', $templateIds)
                ->get()
                ->keyBy(static fn (ShiftTemplate $template): int => (int) $template->id)
                ->all();

        $windows = [];
        $punchesByDay = [];
        $minStart = null;
        $maxEnd = null;

        foreach ($rows as $row) {
            $key = $this->dayKey((int) $row->employment_id, $row->business_date->format('Y-m-d'));
            $template = $row->shift_template_id !== null
                ? ($templates[(int) $row->shift_template_id] ?? null)
                : null;

            if ($template === null) {
                $start = CarbonImmutable::parse($row->business_date->format('Y-m-d').' 00:00:00', $timezone)->utc();
                $end = $start->addDay();
            } else {
                [$localStart, $localEnd] = $this->windows->boundaries(
                    $timezone,
                    $row->business_date->format('Y-m-d'),
                    (string) $template->start_local_time,
                    (string) $template->end_local_time,
                    (bool) $template->crosses_midnight,
                );
                $start = $localStart->utc();
                $end = $localEnd->utc();
            }

            $windows[$key] = [
                'start' => $start,
                'end' => $end,
                'has_template' => $template !== null,
                'local_date' => $row->business_date->format('Y-m-d'),
            ];

            $minStart = $minStart === null || $start->lessThan($minStart) ? $start : $minStart;
            $maxEnd = $maxEnd === null || $end->greaterThan($maxEnd) ? $end : $maxEnd;
        }

        $employmentIds = array_values(array_unique(array_map(
            static fn (AttendanceDay $row): int => (int) $row->employment_id,
            $rows,
        )));

        if ($minStart !== null && $maxEnd !== null) {
            /** @var Collection<int, PunchRecord> $punches */
            $punches = PunchRecord::query()
                ->whereIn('employment_id', $employmentIds)
                ->where('punched_at_utc', '>=', $minStart)
                ->where('punched_at_utc', '<=', $maxEnd)
                ->orderBy('punched_at_utc')
                ->orderBy('id')
                ->get();

            foreach ($punches as $punch) {
                $instant = $punch->punched_at_utc->utc();
                $employmentId = (int) $punch->employment_id;
                $assigned = false;

                foreach ($windows as $key => $window) {
                    if (! str_starts_with((string) $key, $employmentId.'|')) {
                        continue;
                    }

                    if ($instant->greaterThanOrEqualTo($window['start']) && $instant->lessThanOrEqualTo($window['end'])) {
                        $punchesByDay[$key][] = $punch;
                        $assigned = true;
                    }
                }

                if ($assigned) {
                    continue;
                }

                // A row without a shift is a local-calendar day. This fallback
                // keeps a punch visible when adjacent overnight windows overlap
                // but never invents a business date for an existing row.
                $localDate = $instant->setTimezone($timezone)->format('Y-m-d');
                $fallbackKey = $this->dayKey($employmentId, $localDate);

                if (($windows[$fallbackKey]['has_template'] ?? true) === false) {
                    $punchesByDay[$fallbackKey][] = $punch;
                }
            }
        }

        $snapshots = [];

        return array_map(function (AttendanceDay $row) use ($punchesByDay, &$snapshots): array {
            $employmentId = (int) $row->employment_id;
            $businessDate = $row->business_date->format('Y-m-d');
            $key = $this->dayKey($employmentId, $businessDate);
            $snapshotKey = $employmentId.'|'.$businessDate;

            if (! array_key_exists($snapshotKey, $snapshots)) {
                $snapshots[$snapshotKey] = $this->employments->snapshot($employmentId, $businessDate);
            }

            $snapshot = $snapshots[$snapshotKey];
            /** @var list<PunchRecord> $dayPunches */
            $dayPunches = $punchesByDay[$key] ?? [];
            $sources = array_values(array_unique(array_map(
                static fn (PunchRecord $punch): string => (string) $punch->source,
                $dayPunches,
            )));
            $status = (string) $row->status;
            $displayStatus = $status === 'NORMAL' ? 'PRESENT' : $status;

            return [
                'employment_id' => $employmentId,
                'employee_no' => $snapshot !== null ? $snapshot->employeeNo : '',
                'employee_name' => $snapshot !== null ? $snapshot->employeeName : '',
                'business_date' => $businessDate,
                'first_in' => $dayPunches === [] ? null : $dayPunches[0]->punched_at_utc->toIso8601ZuluString(),
                'last_out' => $dayPunches === [] ? null : $dayPunches[array_key_last($dayPunches)]->punched_at_utc->toIso8601ZuluString(),
                'source' => match (count($sources)) {
                    0 => null,
                    1 => $sources[0],
                    default => 'MIXED',
                },
                'status' => $displayStatus,
                'late_minutes' => (int) $row->late_minutes,
                'early_leave_minutes' => (int) $row->early_leave_minutes,
                'absence_minutes' => (int) $row->absence_minutes,
                'exception_label' => $this->exceptionLabel($row, $displayStatus),
            ];
        }, $rows);
    }

    private function exceptionLabel(AttendanceDay $row, string $displayStatus): ?string
    {
        if ($displayStatus === 'ABSENT') {
            return '缺勤';
        }

        if ($displayStatus === 'INCOMPLETE') {
            return '记录不完整';
        }

        $labels = [];

        if ((int) $row->late_minutes > 0) {
            $labels[] = '迟到 '.(int) $row->late_minutes.' 分钟';
        }

        if ((int) $row->early_leave_minutes > 0) {
            $labels[] = '早退 '.(int) $row->early_leave_minutes.' 分钟';
        }

        if ((int) $row->absence_minutes > 0) {
            $labels[] = '缺勤 '.(int) $row->absence_minutes.' 分钟';
        }

        return $labels === [] ? null : implode('；', $labels);
    }

    private function dayKey(int $employmentId, string $businessDate): string
    {
        return $employmentId.'|'.$businessDate;
    }
}

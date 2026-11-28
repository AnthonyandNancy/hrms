<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Models\AttendanceDevice;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\AttendanceRuleVersion;
use App\Modules\Time\Models\ShiftSchedule;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Attendance operations read/write surface for K4.
 *
 * Invariants enforced here (and, where possible, by a database constraint):
 *  - attendance rules are append-only versions keyed by an effective date;
 *  - one shift schedule per employment per business date;
 *  - a closed attendance period cannot accept new schedule writes;
 *  - device health is read-only and always reflects persisted rows.
 */
final class AttendanceOpsService
{
    /** Platform audit evidence keeps its compatibility scope column. */
    public function __construct(
        private readonly Clock $clock,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceClosingService $closings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Every stored rule version, newest first, plus whether it is the version
     * in force on the requested business date. History is never collapsed:
     * the list is the audit trail the page shows.
     *
     * @return list<array<string, mixed>>
     */
    public function ruleVersions(string $asOf): array
    {
        $effectiveVersion = $this->calculator->ruleVersionFor($asOf);

        return AttendanceRuleVersion::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AttendanceRuleVersion $row): array => [
                'id' => (int) $row->id,
                'version' => (string) $row->version,
                'effective_from' => $row->effective_from->format('Y-m-d'),
                'checksum' => (string) $row->checksum,
                'config' => (array) $row->config_json,
                'is_effective' => (string) $row->version === $effectiveVersion,
                'created_at' => $row->created_at?->toIso8601ZuluString(),
            ])
            ->all();
    }

    /**
     * Publish a new effective-dated rule version.
     *
     * The write is append-only. Re-publishing the exact same version is an
     * idempotent no-op; re-using a version string with a different definition
     * is a conflict so no historical row can be rewritten.
     *
     * @param  array<string, mixed>  $config
     * @return array{row: AttendanceRuleVersion, replayed: bool}
     */
    public function publishRuleVersion(int $actorUserId, string $version, string $effectiveFrom, array $config): array
    {
        $normalised = $this->normaliseConfig($config);

        $existing = AttendanceRuleVersion::query()
            ->where('version', $version)
            ->first();

        if ($existing !== null) {
            $sameConfig = (array) $existing->config_json === $normalised;
            $sameDate = $existing->effective_from->format('Y-m-d') === $effectiveFrom;

            if (! $sameConfig || ! $sameDate) {
                throw new ConflictException(
                    'Attendance rule versions are immutable; publish a new version instead.',
                    ['reason' => 'RULE_VERSION_IMMUTABLE', 'version' => $version],
                );
            }

            return ['row' => $existing, 'replayed' => true];
        }

        $row = $this->calculator->registerRuleVersion($version, $effectiveFrom, $normalised);

        $this->audit->record(
            $actorUserId,
            'time.attendance.rule.published',
            'attendance_rule_version',
            (int) $row->id,
            after: ['version' => (string) $row->version, 'effective_from' => $effectiveFrom],
        );

        return ['row' => $row, 'replayed' => false];
    }

    /**
     * One page of schedules visible to the actor.
     *
     * @param  array{employment_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function schedules(TimeActor $actor, array $filters, int $page, int $pageSize): array
    {
        $query = ShiftSchedule::query();

        $this->applyScheduleScope($query, $actor);

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', $filters['employment_id']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('business_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('business_date', '<=', $filters['to']);
        }

        $total = (clone $query)->count();

        /** @var list<ShiftSchedule> $rows */
        $rows = $query
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        $templateIds = array_values(array_unique(array_map(
            static fn (ShiftSchedule $row): int => (int) $row->shift_template_id,
            $rows,
        )));

        /** @var array<int, ShiftTemplate> $templates */
        $templates = ShiftTemplate::query()
            ->whereIn('id', $templateIds)
            ->get()
            ->keyBy(static fn (ShiftTemplate $template): int => (int) $template->id)
            ->all();

        $items = array_map(static function (ShiftSchedule $row) use ($templates): array {
            $template = $templates[(int) $row->shift_template_id] ?? null;

            return [
                'id' => (int) $row->id,
                'employment_id' => (int) $row->employment_id,
                'business_date' => $row->business_date->format('Y-m-d'),
                'shift_template_id' => (int) $row->shift_template_id,
                'shift_code' => $template?->code,
                'shift_name' => $template?->name,
                'start_local_time' => $template?->start_local_time,
                'end_local_time' => $template?->end_local_time,
                'crosses_midnight' => $template !== null ? (bool) $template->crosses_midnight : null,
                'created_by' => (int) $row->created_by,
                'created_at' => $row->created_at?->toIso8601ZuluString(),
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * Schedule one employment to one shift template for one business date.
     *
     * @return array{row: ShiftSchedule, replayed: bool}
     */
    public function scheduleShift(
        int $employmentId,
        string $businessDate,
        int $shiftTemplateId,
        int $actorUserId,
    ): array {
        return DB::transaction(function () use ($employmentId, $businessDate, $shiftTemplateId, $actorUserId): array {
            $this->closings->assertOpen(substr($businessDate, 0, 7));

            $template = ShiftTemplate::query()
                ->whereKey($shiftTemplateId)
                ->first();

            if ($template === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ($template->valid_from->format('Y-m-d') > $businessDate || $template->valid_to->format('Y-m-d') <= $businessDate) {
                throw new ValidationException(
                    ['shift_template_id' => ['The shift template is not effective on the requested business date.']],
                    'The shift template is not effective on the requested business date.',
                    ['reason' => 'SHIFT_TEMPLATE_NOT_EFFECTIVE'],
                );
            }

            $existing = ShiftSchedule::query()
                ->where('employment_id', $employmentId)
                ->where('business_date', $businessDate)
                ->first();

            if ($existing !== null) {
                if ((int) $existing->shift_template_id === $shiftTemplateId) {
                    return ['row' => $existing, 'replayed' => true];
                }

                throw new ConflictException(
                    'The employment already has a different shift scheduled on this business date.',
                    ['reason' => 'SCHEDULE_CONFLICT', 'business_date' => $businessDate],
                );
            }

            try {
                $row = ShiftSchedule::query()->create([
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'shift_template_id' => $shiftTemplateId,
                    'created_by' => $actorUserId,
                    'created_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The service check and the unique index are belt and braces: a
                // concurrent insert still surfaces as the same stable conflict.
                throw new ConflictException(
                    'The employment already has a different shift scheduled on this business date.',
                    ['reason' => 'SCHEDULE_CONFLICT', 'business_date' => $businessDate],
                );
            }

            $this->audit->record(
                $actorUserId,
                'time.shift.schedule.created',
                'shift_schedule',
                (int) $row->id,
                after: [
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'shift_template_id' => $shiftTemplateId,
                ],
            );

            return ['row' => $row->refresh(), 'replayed' => false];
        });
    }

    /**
     * One page of attendance period closures.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function closures(string $month, int $page, int $pageSize): array
    {
        $query = AttendancePeriodClosure::query();

        if ($month !== '') {
            $query->where('month', $month);
        }

        $total = (clone $query)->count();

        /** @var list<AttendancePeriodClosure> $rows */
        $rows = $query
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        $versionIds = array_values(array_filter(array_map(
            static fn (AttendancePeriodClosure $row): ?int => $row->current_fact_version_id !== null
                ? (int) $row->current_fact_version_id
                : null,
            $rows,
        )));

        /** @var array<int, TimePayrollFactVersion> $versions */
        $versions = $versionIds === []
            ? []
            : TimePayrollFactVersion::query()
                ->whereIn('id', $versionIds)
                ->get()
                ->keyBy(static fn (TimePayrollFactVersion $version): int => (int) $version->id)
                ->all();

        $items = array_map(static function (AttendancePeriodClosure $row) use ($versions): array {
            $version = $row->current_fact_version_id !== null
                ? ($versions[(int) $row->current_fact_version_id] ?? null)
                : null;

            return [
                'id' => (int) $row->id,
                'month' => (string) $row->month,
                'status' => (string) $row->status,
                'closed_by' => $row->closed_by !== null ? (int) $row->closed_by : null,
                'closed_at' => $row->closed_at?->toIso8601ZuluString(),
                'reopened_by' => $row->reopened_by !== null ? (int) $row->reopened_by : null,
                'reopened_at' => $row->reopened_at?->toIso8601ZuluString(),
                'reopen_reason' => $row->reopen_reason !== null ? (string) $row->reopen_reason : null,
                'current_fact_version_id' => $row->current_fact_version_id !== null ? (int) $row->current_fact_version_id : null,
                'fact_version' => $version?->version !== null ? (int) $version->version : null,
                'source_hash' => $version?->source_hash,
                'exception_count' => $version !== null ? (int) $version->exception_count : null,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * Read-only device inventory. There is intentionally no write method: a
     * browser cannot assert device health.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function devices(
        ?bool $active,
        int $page,
        int $pageSize,
    ): array {
        $query = AttendanceDevice::query();

        if ($active !== null) {
            $query->where('active', $active);
        }

        $total = (clone $query)->count();

        /** @var list<AttendanceDevice> $rows */
        $rows = $query
            ->orderBy('code')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        $items = array_map(static fn (AttendanceDevice $row): array => [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'adapter' => (string) $row->adapter,
            'health' => (string) $row->health,
            'last_seen_at' => $row->last_seen_at?->toIso8601ZuluString(),
            'active' => (bool) $row->active,
        ], $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normaliseConfig(array $config): array
    {
        $allowed = [
            'late_grace_source',
            'count_break',
            'overtime_after_scheduled',
            'incomplete_threshold_minutes',
            'rounding_minutes',
        ];
        $unknown = array_values(array_diff(array_keys($config), $allowed));

        if ($unknown !== []) {
            throw new ValidationException(
                ['config' => ['Unknown attendance rule keys: '.implode(', ', $unknown).'.']],
                'The attendance rule configuration contains unsupported keys.',
                ['reason' => 'RULE_CONFIG_UNKNOWN_KEY'],
            );
        }

        return array_merge(AttendanceCalculator::DEFAULT_RULE_CONFIG, $config);
    }

    /**
     * SELF actors only see their own schedule; MANAGED actors see their team;
     * company-scoped actors see the whole company. Never widen a row scope
     * because the route guard happened to accept a broader grant.
     *
     * @param  Builder<ShiftSchedule>  $query
     */
    private function applyScheduleScope(Builder $query, TimeActor $actor): void
    {
        $scope = strtoupper($actor->scope);

        if ($scope === 'SELF') {
            $query->where('employment_id', $actor->employmentId ?? -1);
        } elseif ($scope === 'MANAGED') {
            $query->whereIn('employment_id', $actor->managedEmploymentIds === [] ? [-1] : $actor->managedEmploymentIds);
        }
    }
}

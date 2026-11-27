<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\DepartmentStaffingSnapshot;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\People\Contracts\DepartmentStaffingDirectory;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Owns department headcount decisions and composes them with People's live
 * assignment projection. Headcount history is append-only; `staffed` is a
 * read-only People fact and must never be copied into a version row.
 */
final class DepartmentStaffingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly DepartmentStaffingDirectory $staffing,
    ) {}

    /**
     * @return list<DepartmentStaffingSnapshot>
     */
    public function snapshot(string $on, ?int $orgUnitId = null): array
    {
        $query = OrgUnit::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('code');

        if ($orgUnitId !== null) {
            $query->whereKey($orgUnitId);
        }

        $units = $query->get();

        if ($orgUnitId !== null && $units->isEmpty()) {
            throw new NotFoundOrForbiddenException;
        }

        $ids = $units->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $headcount = $this->staffing->activeHeadcount($ids, $on);
        $versions = $this->latestVersions($ids, $on);

        return $units->map(function (OrgUnit $unit) use ($headcount, $versions): DepartmentStaffingSnapshot {
            $id = (int) $unit->id;
            $staffed = $headcount[$id] ?? 0;
            $version = $versions[$id] ?? null;
            $limit = $version !== null ? (int) $version->headcount_limit : null;

            return new DepartmentStaffingSnapshot(
                orgUnitId: $id,
                code: (string) $unit->code,
                name: (string) $unit->name,
                headcountLimit: $limit,
                staffed: $staffed,
                vacancies: $limit !== null ? max(0, $limit - $staffed) : null,
                overstaffed: $limit !== null && $staffed > $limit,
                version: $version !== null ? (int) $version->id : null,
                effectiveFrom: $version !== null ? (string) $version->effective_from : null,
            );
        })->all();
    }

    /**
     * Append one headcount decision. Repeating the same effective date with
     * the same limit returns the existing row; changing it is refused so a
     * recorded version can never be silently rewritten.
     */
    public function publish(int $orgUnitId, int $headcountLimit, string $effectiveFrom, ?int $actorUserId): OrgUnitHeadcountVersion
    {
        if ($headcountLimit < 0) {
            throw new ValidationException([
                'headcount_limit' => ['The headcount limit must be a non-negative integer.'],
            ]);
        }

        return DB::transaction(function () use ($orgUnitId, $headcountLimit, $effectiveFrom, $actorUserId): OrgUnitHeadcountVersion {
            /** @var OrgUnit|null $unit */
            $unit = OrgUnit::query()
                ->whereKey($orgUnitId)
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ($effectiveFrom < (string) $unit->valid_from || $effectiveFrom >= (string) $unit->valid_to) {
                throw new ValidationException([
                    'effective_from' => ['The headcount version must take effect inside the department validity window.'],
                ]);
            }

            $existing = OrgUnitHeadcountVersion::query()
                ->where('org_unit_id', $orgUnitId)
                ->where('effective_from', $effectiveFrom)
                ->first();

            if ($existing !== null) {
                if ((int) $existing->headcount_limit === $headcountLimit) {
                    return $existing;
                }

                throw new ConflictException(
                    'A headcount version already exists for this effective date with a different limit.',
                    ['reason' => 'ORG_HEADCOUNT_VERSION_CONFLICT', 'org_unit_id' => $orgUnitId, 'effective_from' => $effectiveFrom],
                );
            }

            /** @var OrgUnitHeadcountVersion $version */
            $version = OrgUnitHeadcountVersion::query()->create([
                'org_unit_id' => $orgUnitId,
                'headcount_limit' => $headcountLimit,
                'effective_from' => $effectiveFrom,
                'recorded_at' => $this->clock->now(),
                'recorded_by' => $actorUserId,
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.department.headcount.versioned',
                aggregateType: 'org_unit',
                aggregateId: $orgUnitId,
                before: ['headcount_limit' => $this->latestLimitBefore($orgUnitId, $effectiveFrom)],
                after: [
                    'headcount_limit' => $headcountLimit,
                    'effective_from' => $effectiveFrom,
                    'version_id' => (int) $version->id,
                ],
            );

            return $version;
        });
    }

    private function latestLimitBefore(int $orgUnitId, string $effectiveFrom): ?int
    {
        $value = OrgUnitHeadcountVersion::query()
            ->where('org_unit_id', $orgUnitId)
            ->where('effective_from', '<', $effectiveFrom)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('headcount_limit');

        return $value !== null ? (int) $value : null;
    }

    /**
     * @param  list<int>  $orgUnitIds
     * @return array<int, OrgUnitHeadcountVersion>
     */
    private function latestVersions(array $orgUnitIds, string $on): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        $rows = OrgUnitHeadcountVersion::query()
            ->whereIn('org_unit_id', $orgUnitIds)
            ->where('effective_from', '<=', $on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $id = (int) $row->org_unit_id;

            if (! array_key_exists($id, $latest)) {
                $latest[$id] = $row;
            }
        }

        return $latest;
    }
}

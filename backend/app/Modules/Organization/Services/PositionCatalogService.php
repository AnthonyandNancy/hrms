<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Models\PositionGrade;
use App\Modules\Organization\Models\PositionQualification;
use App\Modules\People\Contracts\AssignmentDirectory;
use App\Modules\People\Contracts\AssignmentOccupant;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Position catalogue reads and writes: qualification versions, the job-grade
 * master data and the as-of staff projection.
 *
 * The staff projection reads assignments through the People contract only;
 * Organization never touches the assignments table.
 */
final class PositionCatalogService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly AssignmentDirectory $assignments,
    ) {}

    public function findPosition(int $positionId): ?Position
    {
        return Position::query()
            ->whereKey($positionId)
            ->first();
    }

    /**
     * Append the next qualification version. Qualification history is
     * append-only: an edit never mutates a recorded version.
     *
     * @param  array<string, mixed>  $requirements
     */
    public function versionQualification(
        int $positionId,
        array $requirements,
        string $effectiveFrom,
        ?int $actorUserId,
    ): PositionQualification {
        return DB::transaction(function () use ($positionId, $requirements, $effectiveFrom, $actorUserId): PositionQualification {
            $position = Position::query()
                ->whereKey($positionId)
                ->lockForUpdate()
                ->first();

            if ($position === null) {
                throw new NotFoundOrForbiddenException;
            }

            $next = (int) PositionQualification::query()
                ->where('position_id', $positionId)
                ->max('version') + 1;

            /** @var PositionQualification $qualification */
            $qualification = PositionQualification::query()->create([
                'position_id' => $positionId,
                'version' => $next,
                'requirements_json' => $requirements,
                'effective_from' => $effectiveFrom,
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.position.qualification.versioned',
                aggregateType: 'position_qualification',
                aggregateId: (int) $qualification->id,
                after: [
                    'position_id' => $positionId,
                    'version' => $next,
                    'effective_from' => $effectiveFrom,
                ],
            );

            return $qualification;
        });
    }

    public function qualificationsProjection(
        int $positionId,
        string $asOf,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = PositionQualification::query()
            ->where('position_id', $positionId)
            ->where('effective_from', '<=', $asOf);

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (PositionQualification $qualification): array => $this->qualificationPayload($qualification))
            ->all();

        return $this->projection($items, $page, $pageSize, $total, $asOf, [
            'resource' => 'position_qualifications',
            'filters' => ['position_id' => $positionId],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function upsertGrade(array $input, ?int $actorUserId): PositionGrade
    {
        return DB::transaction(function () use ($input, $actorUserId): PositionGrade {
            $code = (string) $input['code'];

            /** @var PositionGrade|null $grade */
            $grade = PositionGrade::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            $before = $grade !== null ? $this->gradePayload($grade) : [];

            if ($grade === null) {
                /** @var PositionGrade $grade */
                $grade = PositionGrade::query()->create([
                    'code' => $code,
                    'name' => (string) $input['name'],
                    'level' => (int) $input['level'],
                    'active' => (bool) ($input['active'] ?? true),
                ]);
            } else {
                $grade->forceFill([
                    'name' => (string) $input['name'],
                    'level' => (int) $input['level'],
                    'active' => (bool) ($input['active'] ?? true),
                ])->save();
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: $before === [] ? 'org.position.grade.created' : 'org.position.grade.updated',
                aggregateType: 'position_grade',
                aggregateId: (int) $grade->id,
                before: $before,
                after: $this->gradePayload($grade),
            );

            return $grade->refresh();
        });
    }

    public function gradesProjection(
        string $asOf,
        ?bool $active,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = PositionGrade::query();

        if ($active !== null) {
            $query->where('active', $active);
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('level')
            ->orderBy('code')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (PositionGrade $grade): array => $this->gradePayload($grade))
            ->all();

        return $this->projection($items, $page, $pageSize, $total, $asOf, [
            'resource' => 'position_grades',
            'filters' => ['active' => $active],
        ]);
    }

    public function staffProjection(Position $position, string $asOf): ProjectionResult
    {
        $occupants = $this->assignments->positionIncumbents((int) $position->id, $asOf);

        $items = array_map(static fn (AssignmentOccupant $occupant): array => [
            'assignment_id' => $occupant->assignmentId,
            'employment_id' => $occupant->employmentId,
            'employee_no' => $occupant->employeeNo,
            'person_name' => $occupant->personName,
            'org_unit_id' => $occupant->orgUnitId,
            'position_id' => $occupant->positionId,
            'position_code' => (string) $position->code,
            'position_title' => (string) $position->title,
            'assignment_type' => $occupant->assignmentType,
            'is_primary' => $occupant->isPrimary,
            'valid_from' => $occupant->validFrom,
            'valid_to' => $occupant->validTo,
        ], $occupants);

        return $this->projection($items, 1, max(1, count($items)), count($items), $asOf, [
            'resource' => 'position_staff',
            'filters' => ['position_id' => (int) $position->id],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function qualificationPayload(PositionQualification $qualification): array
    {
        return [
            'id' => (int) $qualification->id,
            'position_id' => (int) $qualification->position_id,
            'version' => (int) $qualification->version,
            'requirements_json' => (array) $qualification->requirements_json,
            'effective_from' => (string) $qualification->effective_from,
            'recorded_at' => $qualification->getAttribute('recorded_at')?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function gradePayload(PositionGrade $grade): array
    {
        return [
            'id' => (int) $grade->id,
            'code' => (string) $grade->code,
            'name' => (string) $grade->name,
            'level' => (int) $grade->level,
            'active' => (bool) $grade->active,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $drilldown
     */
    private function projection(
        array $items,
        int $page,
        int $pageSize,
        int $total,
        string $asOf,
        array $drilldown,
    ): ProjectionResult {
        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: $drilldown,
        );
    }
}

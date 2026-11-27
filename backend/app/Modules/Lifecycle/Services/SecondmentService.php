<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\AssignmentDirectory;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Secondments are a parallel SECONDMEN assignment, not a rewrite of the
 * primary one: the employee keeps their home org/position history untouched
 * while the host assignment carries the temporary arrangement.
 */
final class SecondmentService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly OrganizationFacts $organization,
        private readonly EmploymentLifecycleDirectory $employments,
        private readonly PeopleEmploymentWriter $people,
        private readonly AssignmentDirectory $assignments,
    ) {}

    public function create(
        int $employmentId,
        int $hostOrgUnitId,
        string $effectiveFrom,
        string $effectiveTo,
        string $reason,
        ?int $positionId,
        ?int $actorUserId,
    ): int {
        $employment = $this->employments->employment($employmentId);

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ($employment->status === 'TERMINATED') {
            throw new ConflictException('Cannot second a terminated employment.', ['reason' => 'EMPLOYMENT_TERMINATED']);
        }

        $host = $this->organization->orgUnit($hostOrgUnitId);

        if ($host === null) {
            throw new NotFoundOrForbiddenException;
        }

        $from = BusinessDate::from($effectiveFrom);
        $to = BusinessDate::from($effectiveTo);

        if (! $from->isBefore($to)) {
            throw new ValidationException(
                ['effective_to' => ['The secondment end date must be after the start date.']],
                'The secondment end date must be after the start date.',
                ['reason' => 'SECONDMENT_INVALID_RANGE'],
            );
        }

        if ($from->isBefore(BusinessDate::from($employment->validFrom)) || $to->isAfter(BusinessDate::from($employment->validTo))) {
            throw new ValidationException(
                ['effective_from' => ['The secondment must be within the employment validity window.']],
                'The secondment must be within the employment validity window.',
                ['reason' => 'SECONDMENT_OUTSIDE_EMPLOYMENT'],
            );
        }

        $primary = $this->employments->assignmentAsOf($employmentId, $from->value());

        if ($primary === null || $primary->assignmentType !== 'PRIMARY' || ! $primary->isPrimary) {
            throw new ConflictException('A secondment requires an active primary assignment.', ['reason' => 'SECONDMENT_PRIMARY_MISSING']);
        }

        return DB::transaction(function () use ($employmentId, $hostOrgUnitId, $from, $to, $reason, $positionId, $actorUserId, $primary): int {
            $activeSecondment = $this->assignments->secondments($employmentId);

            foreach ($activeSecondment as $existing) {
                if ($existing->validFrom < $to->value() && $existing->validTo > $from->value()) {
                    throw new ConflictException('The employment already has an overlapping secondment.', [
                        'reason' => 'SECONDMENT_OVERLAP',
                        'assignment_id' => $existing->assignmentId,
                    ]);
                }
            }

            $assignmentId = $this->people->createAssignment($employmentId, [
                'org_unit_id' => $hostOrgUnitId,
                'position_id' => $positionId,
                'manager_assignment_id' => null,
                'assignment_type' => 'SECONDMENT',
                'is_primary' => false,
                'valid_from' => $from->value(),
                'valid_to' => $to->value(),
                'source_type' => 'SECONDMENT',
            ], $actorUserId);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'lifecycle.secondment.started',
                aggregateType: 'assignment',
                aggregateId: $assignmentId,
                after: [
                    'employment_id' => $employmentId,
                    'host_org_unit_id' => $hostOrgUnitId,
                    'position_id' => $positionId,
                    'primary_assignment_id' => $primary->id,
                    'effective_from' => $from->value(),
                    'effective_to' => $to->value(),
                    'reason' => $reason,
                    'recorded_at' => $this->clock->now()->toIso8601String(),
                ],
            );

            return $assignmentId;
        });
    }

    public function find(int $assignmentId): ?\App\Modules\People\Contracts\AssignmentOccupant
    {
        return $this->assignments->occupant($assignmentId);
    }

    public function project(
        ?int $employmentId,
        string $asOf,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $all = $this->assignments->secondments($employmentId);

        $rows = array_values(array_map(static fn (\App\Modules\People\Contracts\AssignmentOccupant $occupant): array => [
            'assignment_id' => $occupant->assignmentId,
            'employment_id' => $occupant->employmentId,
            'employee_no' => $occupant->employeeNo,
            'person_name' => $occupant->personName,
            'host_org_unit_id' => $occupant->orgUnitId,
            'position_id' => $occupant->positionId,
            'assignment_type' => $occupant->assignmentType,
            'valid_from' => $occupant->validFrom,
            'valid_to' => $occupant->validTo,
        ], $all));

        $total = count($rows);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($rows, $offset, $pageSize);

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
            drilldown: [
                'resource' => 'secondments',
                'filters' => ['employment_id' => $employmentId],
            ],
        );
    }
}

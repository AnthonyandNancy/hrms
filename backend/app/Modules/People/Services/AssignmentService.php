<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Exceptions\CrossLegalEntityAssignmentException;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\AsOfQuery;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\DateRange;
use Illuminate\Support\Facades\DB;

final class AssignmentService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly OutboxRecorder $outbox,
        private readonly OrganizationFacts $organization,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(int $tenantId, int $employmentId, array $input, ?int $actorUserId): Assignment
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $input, $actorUserId): Assignment {
            /** @var Employment|null $employment */
            $employment = Employment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($employmentId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            $range = new DateRange((string) $input['valid_from'], (string) ($input['valid_to'] ?? BusinessDate::OPEN_END));

            if ($range->validFrom()->isBefore(BusinessDate::from((string) $employment->valid_from))
                || $range->validTo()->isAfter(BusinessDate::from((string) $employment->valid_to))) {
                throw new ValidationException(['valid_from' => ['Assignment must be within the employment validity window.']]);
            }

            $orgUnit = $this->organization->orgUnit($tenantId, (int) $input['org_unit_id']);

            if ($orgUnit === null) {
                throw new ValidationException(['org_unit_id' => ['The organization unit does not exist in this tenant.']]);
            }

            if (! (new DateRange($orgUnit->validFrom, $orgUnit->validTo))->contains($range->validFrom())) {
                throw new ValidationException(['org_unit_id' => ['The organization unit is not active on the assignment start date.']]);
            }

            if (! (new DateRange($orgUnit->validFrom, $orgUnit->validTo))->contains($range->validTo()->addDays(-1))) {
                throw new ValidationException(['org_unit_id' => ['The organization unit must remain active for the assignment validity window.']]);
            }

            if ($orgUnit->legalEntityId !== (int) $employment->legal_entity_id) {
                throw new CrossLegalEntityAssignmentException;
            }

            $managerAssignmentId = $input['manager_assignment_id'] ?? null;

            if ($managerAssignmentId !== null) {
                $managerExists = Assignment::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey((int) $managerAssignmentId)
                    ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $range->validFrom()->value()))
                    ->exists();

                if (! $managerExists) {
                    throw new ValidationException(['manager_assignment_id' => ['The manager assignment is not valid on the start date.']]);
                }
            }

            $assignmentType = (string) $input['assignment_type'];
            $isPrimary = $assignmentType === 'PRIMARY' || (bool) ($input['is_primary'] ?? false);

            $existing = Assignment::query()
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $employmentId)
                ->lockForUpdate()
                ->get()
                ->map(static fn (Assignment $row): array => [
                    'id' => (int) $row->id,
                    'employment_id' => (int) $row->employment_id,
                    'assignment_type' => (string) $row->assignment_type,
                    'is_primary' => (bool) $row->is_primary,
                    'valid_from' => (string) $row->valid_from,
                    'valid_to' => (string) $row->valid_to,
                ])
                ->all();

            $existing[] = [
                'employment_id' => $employmentId,
                'assignment_type' => $assignmentType,
                'is_primary' => $isPrimary,
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
            ];

            AssignmentTimeline::assertNoPrimaryOverlap($existing);

            $now = $this->clock->now();
            $assignment = Assignment::query()->create([
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'org_unit_id' => (int) $orgUnit->id,
                'position_id' => $input['position_id'] ?? null,
                'manager_assignment_id' => $managerAssignmentId,
                'assignment_type' => $assignmentType,
                'is_primary' => $isPrimary,
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
                'recorded_at' => $now,
                'source_type' => 'API',
            ]);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.assignment.created',
                aggregateType: 'assignment',
                aggregateId: $assignment->id,
                after: [
                    'employment_id' => $employmentId,
                    'org_unit_id' => (int) $assignment->org_unit_id,
                    'assignment_type' => $assignmentType,
                    'is_primary' => $isPrimary,
                    'valid_from' => (string) $assignment->valid_from,
                    'valid_to' => (string) $assignment->valid_to,
                ],
            );

            $this->outbox->record(
                tenantId: $tenantId,
                eventType: 'employment.assignment.changed.v1',
                aggregateType: 'employment',
                aggregateId: (string) $employmentId,
                aggregateVersion: (int) $assignment->id,
                payload: [
                    'employment_id' => $employmentId,
                    'assignment_id' => (int) $assignment->id,
                    'valid_from' => (string) $assignment->valid_from,
                ],
            );

            return $assignment;
        });
    }
}

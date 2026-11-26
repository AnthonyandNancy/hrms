<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Exceptions\CrossLegalEntityAssignmentException;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\Person;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\DateRange;
use Illuminate\Support\Facades\DB;

final class EmploymentService
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
    public function open(int $tenantId, array $input, ?int $actorUserId): Employment
    {
        return DB::transaction(function () use ($tenantId, $input, $actorUserId): Employment {
            $person = Person::query()->where('tenant_id', $tenantId)->whereKey((int) $input['person_id'])->first();
            $entity = $this->organization->legalEntity($tenantId, (int) $input['legal_entity_id']);

            if ($person === null) {
                throw new ValidationException(['person_id' => ['The person does not exist in this tenant.']]);
            }

            if ($entity === null) {
                throw new ValidationException(['legal_entity_id' => ['The legal entity does not exist in this tenant.']]);
            }

            $validFrom = BusinessDate::from((string) $input['valid_from']);
            $validTo = BusinessDate::from((string) ($input['valid_to'] ?? BusinessDate::OPEN_END));
            $range = new DateRange($validFrom, $validTo);
            $hireDate = BusinessDate::from((string) $input['hire_date']);

            if (! $range->contains($hireDate)) {
                throw new ValidationException(['hire_date' => ['Hire date must fall inside the employment validity window.']]);
            }

            $existing = Employment::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entity->id)
                ->where('employee_no', (string) $input['employee_no'])
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new ConflictException(
                    'Employee number must be unique per tenant and legal entity.',
                    ['reason' => 'EMPLOYEE_NO_DUPLICATE', 'employee_no' => (string) $input['employee_no']],
                );
            }

            $now = $this->clock->now();
            $employment = Employment::query()->create([
                'tenant_id' => $tenantId,
                'person_id' => (int) $person->id,
                'legal_entity_id' => $entity->id,
                'employee_no' => (string) $input['employee_no'],
                'employment_type' => (string) $input['employment_type'],
                'status' => (string) ($input['status'] ?? 'ACTIVE'),
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
                'hire_date' => $hireDate->value(),
                'termination_date' => $input['termination_date'] ?? null,
                'seniority_start_date' => (string) ($input['seniority_start_date'] ?? $hireDate->value()),
                'recorded_at' => $now,
                'source_type' => 'API',
            ]);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.employment.created',
                aggregateType: 'employment',
                aggregateId: $employment->id,
                after: [
                    'employment_id' => (int) $employment->id,
                    'person_id' => (int) $person->id,
                    'legal_entity_id' => $entity->id,
                    'employee_no' => (string) $employment->employee_no,
                    'valid_from' => (string) $employment->valid_from,
                ],
            );

            $this->outbox->record(
                tenantId: $tenantId,
                eventType: 'employment.created.v1',
                aggregateType: 'employment',
                aggregateId: (string) $employment->id,
                aggregateVersion: 1,
                payload: [
                    'employment_id' => (int) $employment->id,
                    'person_id' => (int) $person->id,
                    'legal_entity_id' => $entity->id,
                    'valid_from' => (string) $employment->valid_from,
                ],
            );

            return $employment;
        });
    }

    public function archive(int $tenantId, int $employmentId, string $reason, int $actorUserId): Employment
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $reason, $actorUserId): Employment {
            /** @var Employment $employment */
            $employment = Employment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($employmentId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ($employment->archived_at !== null) {
                return $employment;
            }

            $before = [
                'employment_id' => (int) $employment->id,
                'status' => (string) $employment->status,
                'archived_at' => null,
            ];
            $employment->forceFill([
                'archived_at' => $this->clock->now(),
                'archived_by' => $actorUserId,
                'archive_reason' => $reason,
            ])->save();

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.employee.archived',
                aggregateType: 'employment',
                aggregateId: $employment->id,
                before: $before,
                after: [
                    'employment_id' => (int) $employment->id,
                    'archived_at' => $employment->archived_at?->toIso8601ZuluString(),
                    'archived_by' => $employment->archived_by,
                    'archive_reason' => $reason,
                ],
            );

            return $employment;
        });
    }

    public function restore(int $tenantId, int $employmentId, int $actorUserId): Employment
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $actorUserId): Employment {
            /** @var Employment $employment */
            $employment = Employment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($employmentId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            $before = [
                'employment_id' => (int) $employment->id,
                'archived_at' => $employment->archived_at?->toIso8601ZuluString(),
                'archived_by' => $employment->archived_by,
                'archive_reason' => $employment->archive_reason,
            ];
            $employment->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
            ])->save();

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.employee.restored',
                aggregateType: 'employment',
                aggregateId: $employment->id,
                before: $before,
                after: ['employment_id' => (int) $employment->id, 'archived_at' => null],
            );

            return $employment;
        });
    }

    /**
     * Cross-entity transfer contract: end the old employment and create a new
     * employment for the same person. The full effect workflow is Task 5.
     *
     * @param  array<string, mixed>  $input
     */
    public function transferLegalEntity(int $tenantId, int $employmentId, array $input, ?int $actorUserId): Employment
    {
        $current = Employment::query()->where('tenant_id', $tenantId)->whereKey($employmentId)->first();

        if ($current === null) {
            throw new NotFoundOrForbiddenException;
        }

        $effectiveFrom = BusinessDate::from((string) $input['effective_from']);

        if (! $effectiveFrom->isAfter(BusinessDate::from((string) $current->valid_from))) {
            throw new ValidationException(['effective_from' => ['Transfer must start after the current employment start.']]);
        }

        if ((int) $current->legal_entity_id === (int) $input['legal_entity_id']) {
            throw new ValidationException(['legal_entity_id' => ['A legal-entity transfer must target a different entity.']]);
        }

        return DB::transaction(function () use ($tenantId, $current, $effectiveFrom, $input, $actorUserId): Employment {
            $old = Employment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $current->id)
                ->lockForUpdate()
                ->firstOrFail();

            $old->valid_to = $effectiveFrom->value();
            $old->termination_date = $effectiveFrom->value();
            $old->status = 'TERMINATED';
            $old->setAttribute('superseded_at', $this->clock->now());
            $old->save();

            $new = Employment::query()->create([
                'tenant_id' => $tenantId,
                'person_id' => (int) $old->person_id,
                'legal_entity_id' => (int) $input['legal_entity_id'],
                'employee_no' => (string) ($input['employee_no'] ?? $old->employee_no),
                'employment_type' => (string) ($input['employment_type'] ?? $old->employment_type),
                'status' => 'ACTIVE',
                'valid_from' => $effectiveFrom->value(),
                'valid_to' => BusinessDate::OPEN_END,
                'hire_date' => $effectiveFrom->value(),
                'termination_date' => null,
                'seniority_start_date' => (string) $old->seniority_start_date,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'TRANSFER',
            ]);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.employment.transferred',
                aggregateType: 'employment',
                aggregateId: $new->id,
                before: ['employment_id' => (int) $old->id, 'legal_entity_id' => (int) $old->legal_entity_id],
                after: ['employment_id' => (int) $new->id, 'legal_entity_id' => (int) $new->legal_entity_id, 'valid_from' => $effectiveFrom->value()],
            );

            $this->outbox->record(
                tenantId: $tenantId,
                eventType: 'employment.transferred.v1',
                aggregateType: 'employment',
                aggregateId: (string) $new->id,
                aggregateVersion: 1,
                payload: [
                    'person_id' => (int) $old->person_id,
                    'old_employment_id' => (int) $old->id,
                    'new_employment_id' => (int) $new->id,
                    'effective_from' => $effectiveFrom->value(),
                ],
            );

            return $new;
        });
    }

    /**
     * Validate that an assignment's organization is inside the employment's
     * legal entity for the assignment's whole validity window.
     */
    public function assertAssignmentWithinLegalEntity(Employment $employment, int $orgUnitId, DateRange $range): void
    {
        $orgUnit = $this->organization->orgUnit((int) $employment->tenant_id, $orgUnitId);

        if ($orgUnit === null || $orgUnit->legalEntityId !== (int) $employment->legal_entity_id) {
            throw new CrossLegalEntityAssignmentException;
        }

        if (! (new DateRange($orgUnit->validFrom, $orgUnit->validTo))->contains($range->validFrom())) {
            throw new ValidationException(['org_unit_id' => ['The organization unit is not active on the assignment start date.']]);
        }
    }
}

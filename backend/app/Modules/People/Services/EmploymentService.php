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
    public function open(array $input, ?int $actorUserId): Employment
    {
        return DB::transaction(function () use ($input, $actorUserId): Employment {
            $person = Person::query()->whereKey((int) $input['person_id'])->first();

            if ($person === null) {
                throw new ValidationException(['person_id' => ['The person does not exist.']]);
            }

            $validFrom = BusinessDate::from((string) $input['valid_from']);
            $validTo = BusinessDate::from((string) ($input['valid_to'] ?? BusinessDate::OPEN_END));
            $range = new DateRange($validFrom, $validTo);
            $hireDate = BusinessDate::from((string) $input['hire_date']);

            if (! $range->contains($hireDate)) {
                throw new ValidationException(['hire_date' => ['Hire date must fall inside the employment validity window.']]);
            }

            $existing = Employment::query()
                ->where('employee_no', (string) $input['employee_no'])
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new ConflictException(
                    'Employee number must be unique.',
                    ['reason' => 'EMPLOYEE_NO_DUPLICATE', 'employee_no' => (string) $input['employee_no']],
                );
            }

            $now = $this->clock->now();
            $employment = Employment::query()->create([
                'person_id' => (int) $person->id,
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
                actorUserId: $actorUserId,
                action: 'people.employment.created',
                aggregateType: 'employment',
                aggregateId: $employment->id,
                after: [
                    'employment_id' => (int) $employment->id,
                    'person_id' => (int) $person->id,
                    'employee_no' => (string) $employment->employee_no,
                    'valid_from' => (string) $employment->valid_from,
                ],
            );

            $this->outbox->record(
                eventType: 'employment.created.v1',
                aggregateType: 'employment',
                aggregateId: (string) $employment->id,
                aggregateVersion: 1,
                payload: [
                    'employment_id' => (int) $employment->id,
                    'person_id' => (int) $person->id,
                    'valid_from' => (string) $employment->valid_from,
                ],
            );

            return $employment;
        });
    }

    public function archive(int $employmentId, string $reason, int $actorUserId): Employment
    {
        return DB::transaction(function () use ($employmentId, $reason, $actorUserId): Employment {
            /** @var Employment $employment */
            $employment = Employment::query()
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
                actorUserId: $actorUserId,
                action: 'people.employee.archived',
                aggregateType: 'employment',
                aggregateId: $employment->id,
                before: $before,
                after: [
                    'employment_id' => (int) $employment->id,
                    'archived_at' => $employment->archived_at instanceof \Carbon\CarbonInterface ? $employment->archived_at->toIso8601ZuluString() : null,
                    'archived_by' => $employment->archived_by,
                    'archive_reason' => $reason,
                ],
            );

            return $employment;
        });
    }

    public function restore(int $employmentId, int $actorUserId): Employment
    {
        return DB::transaction(function () use ($employmentId, $actorUserId): Employment {
            /** @var Employment $employment */
            $employment = Employment::query()
                ->whereKey($employmentId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            $before = [
                'employment_id' => (int) $employment->id,
                'archived_at' => $employment->archived_at instanceof \Carbon\CarbonInterface ? $employment->archived_at->toIso8601ZuluString() : null,
                'archived_by' => $employment->archived_by,
                'archive_reason' => $employment->archive_reason,
            ];
            $employment->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
            ])->save();

            $this->audit->record(
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
     * Validate that an assignment's organization unit is active on the
     * assignment start date. There is one company, so no entity comparison.
     */
    public function assertAssignmentWithinLegalEntity(Employment $employment, int $orgUnitId, DateRange $range): void
    {
        $orgUnit = $this->organization->orgUnit($orgUnitId);

        if ($orgUnit === null) {
            throw new CrossLegalEntityAssignmentException;
        }

        if (! (new DateRange($orgUnit->validFrom, $orgUnit->validTo))->contains($range->validFrom())) {
            throw new ValidationException(['org_unit_id' => ['The organization unit is not active on the assignment start date.']]);
        }
    }
}

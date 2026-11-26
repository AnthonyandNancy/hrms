<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\EmploymentUserLink;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

/**
 * Transaction-participating People writer used by Lifecycle effects. It owns
 * all People table writes; callers supply validated commands.
 */
final class PeopleEmploymentWriterService implements PeopleEmploymentWriter
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly OutboxRecorder $outbox,
        private readonly PersonService $persons,
        private readonly AssignmentTimeline $timeline,
    ) {}

    public function createPerson(array $person, ?int $actorUserId): int
    {
        $created = $this->persons->open($person, $actorUserId);

        return (int) $created->id;
    }

    public function createEmployment(array $input, ?int $actorUserId): int
    {
        $validFrom = BusinessDate::from((string) $input['valid_from']);
        $validTo = BusinessDate::from((string) ($input['valid_to'] ?? BusinessDate::OPEN_END));

        if (! $validFrom->isBefore($validTo)) {
            throw new ValidationException(['valid_from' => ['Employment validity must be a half-open range.']]);
        }

        $employeeNoTaken = Employment::query()
            ->where('employee_no', (string) $input['employee_no'])
            ->lockForUpdate()
            ->exists();

        if ($employeeNoTaken) {
            throw new ConflictException('Employee number must be unique.', [
                'reason' => 'EMPLOYEE_NO_DUPLICATE',
            ]);
        }

        $now = $this->clock->now();
        $employment = Employment::query()->create([
            'person_id' => (int) $input['person_id'],
            'employee_no' => (string) $input['employee_no'],
            'employment_type' => (string) $input['employment_type'],
            'status' => (string) ($input['status'] ?? 'ACTIVE'),
            'valid_from' => $validFrom->value(),
            'valid_to' => $validTo->value(),
            'hire_date' => (string) ($input['hire_date'] ?? $validFrom->value()),
            'termination_date' => $input['termination_date'] ?? null,
            'seniority_start_date' => (string) ($input['seniority_start_date'] ?? $validFrom->value()),
            'recorded_at' => $now,
            'source_type' => (string) ($input['source_type'] ?? 'CASE'),
            'source_id' => isset($input['source_id']) ? (int) $input['source_id'] : null,
        ]);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.employment.created',
            aggregateType: 'employment',
            aggregateId: $employment->id,
            after: [
                'employment_id' => (int) $employment->id,
                'person_id' => (int) $employment->person_id,
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
                'person_id' => (int) $employment->person_id,
                'valid_from' => (string) $employment->valid_from,
            ],
        );

        return (int) $employment->id;
    }

    public function createAssignment(int $employmentId, array $input, ?int $actorUserId): int
    {
        $existing = Assignment::query()
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
            'assignment_type' => (string) $input['assignment_type'],
            'is_primary' => (string) $input['assignment_type'] === 'PRIMARY' || (bool) ($input['is_primary'] ?? false),
            'valid_from' => (string) $input['valid_from'],
            'valid_to' => (string) ($input['valid_to'] ?? BusinessDate::OPEN_END),
        ];

        $this->timeline->assertNoPrimaryOverlap($existing);

        $assignment = Assignment::query()->create([
            'employment_id' => $employmentId,
            'org_unit_id' => (int) $input['org_unit_id'],
            'position_id' => isset($input['position_id']) ? (int) $input['position_id'] : null,
            'manager_assignment_id' => isset($input['manager_assignment_id']) ? (int) $input['manager_assignment_id'] : null,
            'assignment_type' => (string) $input['assignment_type'],
            'is_primary' => (string) $input['assignment_type'] === 'PRIMARY' || (bool) ($input['is_primary'] ?? false),
            'valid_from' => (string) $input['valid_from'],
            'valid_to' => (string) ($input['valid_to'] ?? BusinessDate::OPEN_END),
            'recorded_at' => $this->clock->now(),
            'source_type' => (string) ($input['source_type'] ?? 'CASE'),
            'source_id' => isset($input['source_id']) ? (int) $input['source_id'] : null,
        ]);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.assignment.created',
            aggregateType: 'assignment',
            aggregateId: $assignment->id,
            after: [
                'employment_id' => $employmentId,
                'org_unit_id' => (int) $assignment->org_unit_id,
                'assignment_type' => (string) $assignment->assignment_type,
                'is_primary' => (bool) $assignment->is_primary,
                'valid_from' => (string) $assignment->valid_from,
                'valid_to' => (string) $assignment->valid_to,
            ],
        );

        $this->outbox->record(
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

        return (int) $assignment->id;
    }

    public function appendTerms(int $employmentId, array $input, ?int $actorUserId): int
    {
        $employment = Employment::query()->whereKey($employmentId)->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $effectiveFrom = BusinessDate::from((string) $input['effective_from']);

        if ($effectiveFrom->isBefore(BusinessDate::from((string) $employment->valid_from))
            || $effectiveFrom->compareTo(BusinessDate::from((string) $employment->valid_to)) >= 0) {
            throw new ValidationException(['effective_from' => ['Terms must start inside the employment validity window.']]);
        }

        $latest = EmploymentTerms::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();

        if ($latest !== null && $effectiveFrom->compareTo(BusinessDate::from((string) $latest->effective_from)) <= 0) {
            throw new ConflictException('New terms must take effect after the current terms version.', [
                'reason' => 'TERMS_EFFECTIVE_DATE_NOT_MONOTONIC',
            ]);
        }

        if ($latest !== null) {
            $latest->effective_to = $effectiveFrom->value();
            $latest->save();
        }

        $terms = EmploymentTerms::query()->create([
            'employment_id' => $employmentId,
            'version' => $latest !== null ? (int) $latest->version + 1 : 1,
            'effective_from' => $effectiveFrom->value(),
            'effective_to' => (string) ($input['effective_to'] ?? $employment->valid_to),
            'probation_end_date' => $input['probation_end_date'] ?? null,
            'work_location' => (string) $input['work_location'],
            'work_schedule' => (string) $input['work_schedule'],
            'salary_scheme_code' => $input['salary_scheme_code'] ?? null,
            'base_salary_minor' => isset($input['base_salary_minor']) ? (int) $input['base_salary_minor'] : null,
            'salary_currency' => (string) ($input['salary_currency'] ?? 'CNY'),
            'notice_period_days' => (int) ($input['notice_period_days'] ?? 0),
            'confidentiality' => (bool) ($input['confidentiality'] ?? false),
            'non_compete' => (bool) ($input['non_compete'] ?? false),
            'signed_at' => $input['signed_at'] ?? null,
            'source_type' => (string) ($input['source_type'] ?? 'CASE'),
            'source_id' => isset($input['source_id']) ? (int) $input['source_id'] : null,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.terms.versioned',
            aggregateType: 'employment',
            aggregateId: $employmentId,
            after: [
                'terms_id' => (int) $terms->id,
                'version' => (int) $terms->version,
                'effective_from' => (string) $terms->effective_from,
            ],
        );

        $this->outbox->record(
            eventType: 'employment.terms.changed.v1',
            aggregateType: 'employment',
            aggregateId: (string) $employmentId,
            aggregateVersion: (int) $terms->version,
            payload: [
                'employment_id' => $employmentId,
                'terms_id' => (int) $terms->id,
                'version' => (int) $terms->version,
                'effective_from' => (string) $terms->effective_from,
            ],
        );

        return (int) $terms->id;
    }

    public function endAssignment(int $assignmentId, string $endExclusive, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void
    {
        $assignment = Assignment::query()->whereKey($assignmentId)->lockForUpdate()->first();

        if ($assignment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $end = BusinessDate::from($endExclusive);

        if (! $end->isAfter(BusinessDate::from((string) $assignment->valid_from))) {
            throw new ValidationException(['valid_to' => ['Assignment end must be after its start.']]);
        }

        $assignment->forceFill([
            'valid_to' => $end->value(),
            'superseded_at' => $this->clock->now(),
        ])->save();

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.assignment.ended',
            aggregateType: 'assignment',
            aggregateId: $assignmentId,
            after: ['valid_to' => $end->value(), 'source_type' => $sourceType],
        );
    }

    public function endEmployment(int $employmentId, string $endExclusive, string $lastWorkingDate, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void
    {
        $employment = Employment::query()->whereKey($employmentId)->lockForUpdate()->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $end = BusinessDate::from($endExclusive);

        if (! $end->isAfter(BusinessDate::from((string) $employment->valid_from))) {
            throw new ValidationException(['valid_to' => ['Employment end must be after its start.']]);
        }

        $employment->forceFill([
            'status' => 'TERMINATED',
            'termination_date' => $lastWorkingDate,
            'valid_to' => $end->value(),
            'superseded_at' => $this->clock->now(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ])->save();

        $this->outbox->record(
            eventType: 'employment.terminated.v1',
            aggregateType: 'employment',
            aggregateId: (string) $employmentId,
            aggregateVersion: 1,
            payload: [
                'employment_id' => $employmentId,
                'last_working_date' => $lastWorkingDate,
                'valid_to' => $end->value(),
            ],
        );
    }

    public function setEmploymentStatus(int $employmentId, string $status, ?int $actorUserId): void
    {
        if (! in_array($status, Employment::STATUSES, true)) {
            throw new ValidationException(['status' => ['Unsupported employment status.']]);
        }

        $employment = Employment::query()->whereKey($employmentId)->lockForUpdate()->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $before = (string) $employment->status;
        $employment->forceFill(['status' => $status])->save();

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.employment.status_changed',
            aggregateType: 'employment',
            aggregateId: $employmentId,
            before: ['status' => $before],
            after: ['status' => $status],
        );

        $this->outbox->record(
            eventType: 'employment.status.changed.v1',
            aggregateType: 'employment',
            aggregateId: (string) $employmentId,
            aggregateVersion: 1,
            payload: ['employment_id' => $employmentId, 'status' => $status],
        );
    }

    public function appendDocument(int $employmentId, array $input, ?int $actorUserId): int
    {
        $version = (int) Document::query()
            ->where('owner_type', 'EMPLOYMENT')
            ->where('owner_id', $employmentId)
            ->where('category', 'CONTRACT')
            ->max('version') + 1;

        $document = Document::query()->create([
            'owner_type' => 'EMPLOYMENT',
            'owner_id' => $employmentId,
            'category' => 'CONTRACT',
            'file_object_id' => (int) $input['file_object_id'],
            'version' => $version,
            'signed_at' => $input['signed_at'] ?? null,
            'sha256' => (string) $input['sha256'],
            'expires_at' => $input['expires_at'] ?? null,
            'retention_class' => (string) ($input['retention_class'] ?? 'CONTRACT_10Y'),
            'legal_hold' => (bool) ($input['legal_hold'] ?? false),
        ]);

        return (int) $document->id;
    }

    public function linkUser(int $userId, int $employmentId, string $validFrom, ?string $validTo, ?int $actorUserId): void
    {
        EmploymentUserLink::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'employment_id' => $employmentId,
                'valid_from' => $validFrom,
            ],
            [
                'valid_to' => $validTo ?? BusinessDate::OPEN_END,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'CASE',
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmployeeChangeApprovalPort;
use App\Modules\People\Contracts\EmployeeProfileVersionWriter;
use App\Modules\People\Models\EmployeeCertificate;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Models\EmployeeContact;
use App\Modules\People\Models\EmployeeEducation;
use App\Modules\People\Models\EmployeeExperience;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Versioned People-owned profile subresources. Controllers never write models;
 * this service enforces append-only history, masked P2 output, field
 * whitelisting for approved effects and audit evidence for every mutation.
 */
final class EmployeeProfileService implements EmployeeProfileVersionWriter
{
    public const PROFILE_RESOURCES = ['education', 'experiences', 'contacts', 'certificates'];

    /** @var array<string, list<string>> */
    public const MUTABLE_FIELDS = [
        'education' => ['school', 'degree', 'major', 'start_date', 'end_date', 'notes'],
        'experiences' => ['company', 'title', 'start_date', 'end_date', 'leave_reason', 'notes'],
        'contacts' => ['name', 'relationship', 'phone', 'email', 'is_emergency', 'is_primary'],
        'certificates' => ['certificate_type', 'certificate_no', 'issuer', 'issued_on', 'expires_on', 'document_id'],
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly CompanyBusinessDate $businessDates,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $resource, int $employmentId): array
    {
        $this->assertSupportedResource($resource);
        $model = $this->model($resource);
        $query = $model::query()->where('employment_id', $employmentId);

        if ($resource !== 'change_requests') {
            $query->whereNull('superseded_at');
        }

        return $query
            ->orderByDesc('id')
            ->get()
            ->map(fn (Model $row): array => $this->payload($resource, $row))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function open(string $resource, int $employmentId, array $data, int $actorUserId): array
    {
        $this->assertSupportedResource($resource);
        $model = $this->model($resource);
        $normalized = $this->normalize($resource, $data);
        $normalized['employment_id'] = $employmentId;
        $normalized['recorded_at'] = $this->clock->now();

        if ($resource === 'change_requests') {
            $normalized['requested_by'] = $actorUserId;
            $normalized['status'] = 'DRAFT';
        }

        /** @var Model $row */
        $row = $model::query()->create($normalized);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'people.profile.opened',
            aggregateType: $resource,
            aggregateId: (int) $row->getKey(),
            after: [
                'employment_id' => $employmentId,
                'resource' => $resource,
                'record_id' => (int) $row->getKey(),
            ],
        );

        return $this->payload($resource, $row);
    }

    /**
     * Append a new version and retire the previous version. Patch semantics
     * merge only supplied fields over the live row; absent fields are never
     * reset to empty values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function revise(string $resource, int $employmentId, int $id, array $data, ?int $actorUserId = null): array
    {
        $this->assertProfileResource($resource);
        $model = $this->model($resource);

        return DB::transaction(function () use ($resource, $employmentId, $id, $data, $actorUserId, $model): array {
            $current = $model::query()
                ->whereKey($id)
                ->where('employment_id', $employmentId)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new NotFoundOrForbiddenException;
            }

            $normalized = $this->normalize(
                $resource,
                array_replace($this->currentInput($resource, $current), $data),
            );

            $copy = $current->replicate();
            $copy->forceFill($normalized);
            $copy->forceFill([
                'recorded_at' => $this->clock->now(),
                'superseded_at' => null,
            ]);
            $copy->save();

            $current->forceFill(['superseded_at' => $this->clock->now()])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.profile.revised',
                aggregateType: $resource,
                aggregateId: (int) $copy->getKey(),
                before: ['record_id' => (int) $current->getKey(), 'superseded_at' => $this->iso($current->getAttribute('superseded_at'))],
                after: [
                    'employment_id' => $employmentId,
                    'resource' => $resource,
                    'record_id' => (int) $copy->getKey(),
                    'supersedes_id' => (int) $current->getKey(),
                    'changed_fields' => array_values(array_intersect(array_keys($data), self::MUTABLE_FIELDS[$resource])),
                ],
            );

            return $this->payload($resource, $copy);
        });
    }

    /**
     * Append-only retirement. The row is never deleted; its effective history
     * ends at superseded_at. A repeat on an already retired version is a stable
     * 409 (an idempotent replay with the same key is handled by CommandRunner).
     *
     * @return array{retired: true, id: int, employment_id: int, superseded_at: string|null}
     */
    public function retire(
        string $resource,
        int $employmentId,
        int $id,
        string $reason,
        ?string $effectiveFrom,
        int $actorUserId,
    ): array {
        if (! in_array($resource, self::PROFILE_RESOURCES, true)) {
            throw new NotFoundOrForbiddenException;
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(
                ['reason' => ['A retire reason of at least 3 characters is required.']],
                'A retire reason is required.',
                ['reason' => 'RETIRE_REASON_REQUIRED'],
            );
        }

        $model = $this->model($resource);

        return DB::transaction(function () use ($resource, $employmentId, $id, $reason, $effectiveFrom, $actorUserId, $model): array {
            $row = $model::query()
                ->whereKey($id)
                ->where('employment_id', $employmentId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ($row->getAttribute('superseded_at') !== null) {
                throw new ConflictException(
                    'This profile record is already retired or superseded.',
                    ['reason' => 'PROFILE_ALREADY_RETIRED', 'record_id' => $id, 'resource' => $resource],
                );
            }

            $supersededAt = $this->clock->now();
            $row->forceFill(['superseded_at' => $supersededAt])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.profile.retired',
                aggregateType: $resource,
                aggregateId: $id,
                before: ['employment_id' => $employmentId, 'resource' => $resource, 'record_id' => $id, 'superseded_at' => null],
                after: [
                    'employment_id' => $employmentId,
                    'resource' => $resource,
                    'record_id' => $id,
                    'superseded_at' => $supersededAt->toIso8601ZuluString(),
                ],
                metadata: [
                    'reason' => $reason,
                    'effective_from' => $effectiveFrom,
                ],
            );

            return [
                'retired' => true,
                'id' => (int) $row->getKey(),
                'employment_id' => $employmentId,
                'superseded_at' => $supersededAt->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * Draft change requests are mutable until submission. Unlike profile rows
     * they are workflow intents, so the draft is edited in place and keeps the
     * same id the submit command will use.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateChangeRequestDraft(int $employmentId, int $id, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($employmentId, $id, $data, $actorUserId): array {
            $request = EmployeeChangeRequest::query()
                ->where('employment_id', $employmentId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $request->getAttribute('status') !== 'DRAFT') {
                throw new ConflictException(
                    'Submitted change requests are read-only.',
                    ['reason' => 'CHANGE_REQUEST_NOT_DRAFT'],
                );
            }

            $before = [
                'reason' => (string) $request->getAttribute('reason'),
                'diff' => (array) ($request->getAttribute('diff_json') ?? []),
            ];
            $request->forceFill([
                'reason' => (string) ($data['reason'] ?? $request->getAttribute('reason')),
                'diff_json' => array_key_exists('diff', $data)
                    ? (array) $data['diff']
                    : (array) ($request->getAttribute('diff_json') ?? []),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.change.draft_updated',
                aggregateType: 'employee_change_request',
                aggregateId: (int) $request->getKey(),
                before: $before,
                after: [
                    'employment_id' => $employmentId,
                    'reason' => (string) $request->getAttribute('reason'),
                    'diff' => (array) ($request->getAttribute('diff_json') ?? []),
                ],
            );

            return $this->payload('change_requests', $request);
        });
    }

    /**
     * Submit a draft change request and open a real Workflow Case through the
     * People approval port. The case id is persisted on the request; a failed
     * case open rolls the status update back.
     *
     * @return array<string, mixed>
     */
    public function submitChangeRequest(int $employmentId, int $id, int $actorUserId): array
    {
        return DB::transaction(function () use ($employmentId, $id, $actorUserId): array {
            $request = EmployeeChangeRequest::query()
                ->where('employment_id', $employmentId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $request->getAttribute('status') !== 'DRAFT') {
                throw new ConflictException(
                    'Submitted change requests are read-only.',
                    ['reason' => 'CHANGE_REQUEST_NOT_DRAFT'],
                );
            }

            $diff = (array) ($request->getAttribute('diff_json') ?? []);
            [$resource, $recordId, $changes] = $this->changeTarget($diff);

            $approval = app(EmployeeChangeApprovalPort::class)->open(
                actorUserId: $actorUserId,
                employmentId: $employmentId,
                changeRequestId: (int) $request->getKey(),
                resource: $resource,
                recordId: $recordId,
                changes: $changes,
                reason: (string) $request->getAttribute('reason'),
                businessDate: $this->businessDates->today()->value(),
            );

            $request->forceFill([
                'status' => 'SUBMITTED',
                'submitted_at' => $this->clock->now(),
                'requested_by' => $actorUserId,
                'case_id' => (int) $approval['case_id'],
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.change.submitted',
                aggregateType: 'employee_change_request',
                aggregateId: (int) $request->getKey(),
                after: [
                    'employment_id' => $employmentId,
                    'change_request_id' => (int) $request->getKey(),
                    'case_id' => (int) $approval['case_id'],
                    'case_no' => $approval['case_no'],
                ],
            );

            return [
                ...$this->payload('change_requests', $request),
                'case_no' => (string) $approval['case_no'],
            ];
        });
    }

    /**
     * Apply exactly one approved profile change. The approval is revalidated by
     * the Workflow effect handler; this method independently rechecks the
     * persisted case id and only mutates whitelisted resource fields.
     *
     * @param  array<string, mixed>  $changes
     * @return array{replayed: bool, record: array<string, mixed>}
     */
    public function applyApprovedChange(
        int $employmentId,
        int $changeRequestId,
        string $resource,
        int $recordId,
        array $changes,
        ?int $approverUserId,
        int $caseId,
    ): array {
        return app(EmployeeProfileChangeApplier::class)->apply(
            employmentId: $employmentId,
            changeRequestId: $changeRequestId,
            resource: $resource,
            recordId: $recordId,
            changes: $changes,
            approverUserId: $approverUserId,
            caseId: $caseId,
        );
    }

    /** Public read used by the approved-change applier for replay projection. */
    public function readChangeRequest(int $employmentId, int $id): array
    {
        $row = EmployeeChangeRequest::query()
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->first();

        if ($row === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $this->payload('change_requests', $row);
    }

    /**
     * @param  array<string, mixed>  $diff
     * @return array{0: string|null, 1: int|null, 2: array<string, mixed>}
     */
    private function changeTarget(array $diff): array
    {
        $resource = is_string($diff['resource'] ?? null) ? (string) $diff['resource'] : null;
        $recordId = isset($diff['record_id']) && is_numeric($diff['record_id']) ? (int) $diff['record_id'] : null;
        $changes = is_array($diff['changes'] ?? null) ? (array) $diff['changes'] : [];

        if ($resource === null || ! in_array($resource, self::PROFILE_RESOURCES, true) || $recordId === null || $recordId <= 0 || $changes === []) {
            throw new ValidationException(
                ['diff' => ['A resource, positive record_id and at least one field change are required.']],
                'The change request has no actionable profile target.',
                ['reason' => 'CHANGE_TARGET_REQUIRED'],
            );
        }

        return [$resource, $recordId, $changes];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(string $resource, array $data): array
    {
        return match ($resource) {
            'education' => [
                'school' => (string) ($data['school'] ?? ''),
                'degree' => (string) ($data['degree'] ?? ''),
                'major' => isset($data['major']) ? (string) $data['major'] : null,
                'start_date' => (string) ($data['start_date'] ?? ''),
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            ],
            'experiences' => [
                'company' => (string) ($data['company'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'start_date' => (string) ($data['start_date'] ?? ''),
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'leave_reason' => isset($data['leave_reason']) ? (string) $data['leave_reason'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            ],
            'contacts' => [
                'name' => (string) ($data['name'] ?? ''),
                'relationship' => (string) ($data['relationship'] ?? ''),
                // The model's `encrypted` cast performs encryption at rest. The
                // API never returns this value; payload() always masks it.
                'phone_ciphertext' => isset($data['phone']) ? (string) $data['phone'] : null,
                'email' => isset($data['email']) ? (string) $data['email'] : null,
                'is_emergency' => (bool) ($data['is_emergency'] ?? false),
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ],
            'certificates' => [
                'certificate_type' => (string) ($data['certificate_type'] ?? ''),
                'certificate_no' => isset($data['certificate_no']) ? (string) $data['certificate_no'] : null,
                'issuer' => isset($data['issuer']) ? (string) $data['issuer'] : null,
                'issued_on' => isset($data['issued_on']) ? (string) $data['issued_on'] : null,
                'expires_on' => isset($data['expires_on']) ? (string) $data['expires_on'] : null,
                'document_id' => isset($data['document_id']) ? (int) $data['document_id'] : null,
            ],
            'change_requests' => [
                'reason' => (string) ($data['reason'] ?? ''),
                'diff_json' => $data['diff'] ?? [],
            ],
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };
    }

    /**
     * Reconstruct the write input for a live row. Contacts use the decrypted
     * ciphertext accessor only inside People; the payload remains masked.
     *
     * @return array<string, mixed>
     */
    private function currentInput(string $resource, Model $row): array
    {
        return match ($resource) {
            'education' => [
                'school' => $row->getAttribute('school'),
                'degree' => $row->getAttribute('degree'),
                'major' => $row->getAttribute('major'),
                'start_date' => $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'notes' => $row->getAttribute('notes'),
            ],
            'experiences' => [
                'company' => $row->getAttribute('company'),
                'title' => $row->getAttribute('title'),
                'start_date' => $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'leave_reason' => $row->getAttribute('leave_reason'),
                'notes' => $row->getAttribute('notes'),
            ],
            'contacts' => [
                'name' => $row->getAttribute('name'),
                'relationship' => $row->getAttribute('relationship'),
                'phone' => $row->getAttribute('phone_ciphertext'),
                'email' => $row->getAttribute('email'),
                'is_emergency' => (bool) $row->getAttribute('is_emergency'),
                'is_primary' => (bool) $row->getAttribute('is_primary'),
            ],
            'certificates' => [
                'certificate_type' => $row->getAttribute('certificate_type'),
                'certificate_no' => $row->getAttribute('certificate_no'),
                'issuer' => $row->getAttribute('issuer'),
                'issued_on' => $row->getAttribute('issued_on'),
                'expires_on' => $row->getAttribute('expires_on'),
                'document_id' => $row->getAttribute('document_id'),
            ],
            'change_requests' => [
                'reason' => $row->getAttribute('reason'),
                'diff' => $row->getAttribute('diff_json') ?? [],
            ],
            default => [],
        };
    }

    /**
     * @return class-string<Model>
     */
    private function model(string $resource): string
    {
        return match ($resource) {
            'education' => EmployeeEducation::class,
            'experiences' => EmployeeExperience::class,
            'contacts' => EmployeeContact::class,
            'certificates' => EmployeeCertificate::class,
            'change_requests' => EmployeeChangeRequest::class,
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };
    }

    private function assertProfileResource(string $resource): void
    {
        if (! in_array($resource, self::PROFILE_RESOURCES, true)) {
            throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']);
        }
    }

    /**
     * The create path also supports the People-owned draft change request,
     * while revise/retire/effect remain limited to versioned profile rows.
     */
    private function assertSupportedResource(string $resource): void
    {
        if (! in_array($resource, [...self::PROFILE_RESOURCES, 'change_requests'], true)) {
            throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $resource, Model $row): array
    {
        $base = [
            'id' => (int) $row->getKey(),
            'employment_id' => (int) $row->getAttribute('employment_id'),
            'recorded_at' => $this->iso($row->getAttribute('recorded_at')),
            'superseded_at' => $this->iso($row->getAttribute('superseded_at')),
        ];

        $payload = match ($resource) {
            'education' => [
                'school' => (string) $row->getAttribute('school'),
                'degree' => (string) $row->getAttribute('degree'),
                'major' => $row->getAttribute('major'),
                'start_date' => (string) $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'notes' => $row->getAttribute('notes'),
            ],
            'experiences' => [
                'company' => (string) $row->getAttribute('company'),
                'title' => (string) $row->getAttribute('title'),
                'start_date' => (string) $row->getAttribute('start_date'),
                'end_date' => $row->getAttribute('end_date'),
                'leave_reason' => $row->getAttribute('leave_reason'),
                'notes' => $row->getAttribute('notes'),
            ],
            'contacts' => [
                'name' => (string) $row->getAttribute('name'),
                'relationship' => (string) $row->getAttribute('relationship'),
                'phone' => [
                    'display' => null,
                    'masked' => true,
                    'profile' => FieldProfile::P2_SENSITIVE->value,
                    'revealable' => false,
                    'exportable' => false,
                ],
                'email' => $row->getAttribute('email'),
                'is_emergency' => (bool) $row->getAttribute('is_emergency'),
                'is_primary' => (bool) $row->getAttribute('is_primary'),
            ],
            'certificates' => [
                'certificate_type' => (string) $row->getAttribute('certificate_type'),
                'certificate_no' => $row->getAttribute('certificate_no'),
                'issuer' => $row->getAttribute('issuer'),
                'issued_on' => $row->getAttribute('issued_on'),
                'expires_on' => $row->getAttribute('expires_on'),
                'document_id' => $row->getAttribute('document_id'),
            ],
            'change_requests' => [
                'status' => (string) $row->getAttribute('status'),
                'reason' => (string) $row->getAttribute('reason'),
                'diff' => $row->getAttribute('diff_json') ?? [],
                'requested_by' => (int) $row->getAttribute('requested_by'),
                'case_id' => $row->getAttribute('case_id'),
                'submitted_at' => $this->iso($row->getAttribute('submitted_at')),
            ],
            default => throw new ValidationException([], 'Unknown profile resource.', ['reason' => 'UNKNOWN_PROFILE_RESOURCE']),
        };

        return [...$base, ...$payload];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}

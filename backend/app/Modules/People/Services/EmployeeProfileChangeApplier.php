<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmployeeProfileVersionWriter;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Applies an already-approved employee-profile change.
 *
 * This writer exists separately from EmployeeProfileService so the Workflow
 * effect can depend on it without creating a container cycle:
 *
 *   EmployeeProfileService -> EmployeeChangeApprovalPort
 *   EmployeeChangeWorkflowService -> Workflow
 *   EmployeeProfileChangeEffectHandler -> EmployeeProfileChangeApplier
 *
 * It deliberately has no approval-port dependency; approval was already
 * recorded by Workflow before this service is called.
 */
final class EmployeeProfileChangeApplier
{
    public function __construct(
        private readonly EmployeeProfileVersionWriter $profiles,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     * @return array{replayed: bool, record: array<string, mixed>}
     */
    public function apply(
        int $employmentId,
        int $changeRequestId,
        string $resource,
        int $recordId,
        array $changes,
        ?int $approverUserId,
        int $caseId,
    ): array {
        return DB::transaction(function () use ($employmentId, $changeRequestId, $resource, $recordId, $changes, $approverUserId, $caseId): array {
            $request = EmployeeChangeRequest::query()
                ->whereKey($changeRequestId)
                ->where('employment_id', $employmentId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((int) ($request->getAttribute('case_id') ?? 0) !== $caseId) {
                throw new ConflictException(
                    'The approval case does not own this change request.',
                    ['reason' => 'CHANGE_CASE_MISMATCH'],
                );
            }

            if ((string) $request->getAttribute('status') === 'APPROVED') {
                return ['replayed' => true, 'record' => $this->profiles->readChangeRequest($employmentId, $changeRequestId)];
            }

            if (! in_array((string) $request->getAttribute('status'), ['SUBMITTED', 'IN_APPROVAL'], true)) {
                throw new ConflictException(
                    'Only a submitted change request can be applied.',
                    ['reason' => 'CHANGE_REQUEST_NOT_APPLICABLE', 'status' => (string) $request->getAttribute('status')],
                );
            }

            [$storedResource, $storedRecordId] = $this->changeTarget((array) ($request->getAttribute('diff_json') ?? []));

            if ($storedResource !== $resource || $storedRecordId !== $recordId) {
                throw new ConflictException(
                    'The approved change target differs from the stored change request.',
                    ['reason' => 'CHANGE_TARGET_MISMATCH'],
                );
            }

            $whitelisted = [];
            foreach ($changes as $field => $value) {
                if (is_string($field) && in_array($field, EmployeeProfileService::MUTABLE_FIELDS[$resource] ?? [], true)) {
                    $whitelisted[$field] = $value;
                }
            }

            if ($whitelisted === []) {
                throw new ValidationException(
                    ['changes' => ['No allowed profile field is present in the approved change.']],
                    'The approved change contains no allowed profile field.',
                    ['reason' => 'CHANGE_FIELDS_NOT_ALLOWED'],
                );
            }

            $record = $this->profiles->revise($resource, $employmentId, $recordId, $whitelisted, $approverUserId);

            $request->forceFill([
                'status' => 'APPROVED',
                'decided_at' => $this->clock->now(),
                'decided_by' => $approverUserId,
            ])->save();

            $this->audit->record(
                actorUserId: $approverUserId,
                action: 'people.employee.change.applied',
                aggregateType: 'employee_change_request',
                aggregateId: $changeRequestId,
                after: [
                    'employment_id' => $employmentId,
                    'change_request_id' => $changeRequestId,
                    'case_id' => $caseId,
                    'resource' => $resource,
                    'record_id' => $recordId,
                    'changed_fields' => array_keys($whitelisted),
                    'new_record_id' => (int) ($record['id'] ?? 0),
                ],
            );

            return ['replayed' => false, 'record' => $record];
        });
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

        if ($resource === null || ! in_array($resource, EmployeeProfileService::PROFILE_RESOURCES, true) || $recordId === null || $recordId <= 0 || $changes === []) {
            throw new ValidationException(
                ['diff' => ['A resource, positive record_id and at least one field change are required.']],
                'The change request has no actionable profile target.',
                ['reason' => 'CHANGE_TARGET_REQUIRED'],
            );
        }

        return [$resource, $recordId, $changes];
    }
}

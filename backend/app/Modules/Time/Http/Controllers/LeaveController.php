<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveRequestService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self leave workspace reads, preview and commands.
 *
 * `employment_id` is intentionally not accepted by the self submit/preview:
 * the subject is always session.employment_id. HR/on-behalf uses the separate
 * controller and capability.
 */
final class LeaveController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveRequestService $requests,
        private readonly WorkflowCasePort $workflow,
        private readonly CommandRunner $commands,
        private readonly EmploymentDirectory $employments,
        private readonly OrganizationFacts $organization,
    ) {}

    public function balances(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null);
        $resolved = $this->scope->selfEmployment($actor, $asOf, $employmentId)->employmentId;

        $items = array_map(
            fn (array $balance): array => $this->balancePayload($balance),
            $this->balances->outstanding($resolved, $asOf),
        );

        return ApiResponse::ok($items);
    }

    public function requests(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null);
        $scope = strtolower((string) $request->query('scope', 'self'));

        if ($scope === 'all') {
            if ($actor->scope === 'SELF') {
                throw new NotFoundOrForbiddenException;
            }

            $data = $request->validate([
                'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'leave_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'org_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'state' => ['sometimes', 'nullable', 'string', 'max:24'],
                'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);
            $requestedEmployment = isset($data['employment_id']) ? (int) $data['employment_id']
                : null;

            if ($requestedEmployment !== null && ! $actor->canReadEmployment($requestedEmployment)) {
                throw new NotFoundOrForbiddenException;
            }

            $scopeEmploymentIds = match ($actor->scope) {
                'MANAGED' => $actor->managedEmploymentIds,
                default => [],
            };
            $result = $this->requests->scopedRequests(
                scopeEmploymentIds: $scopeEmploymentIds,
                filters: [
                    'employment_id' => $requestedEmployment,
                    'leave_type_id' => $data['leave_type_id'] ?? null,
                    'org_unit_id' => $data['org_unit_id'] ?? null,
                    'state' => isset($data['state']) ? strtoupper((string) $data['state']) : null,
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                ],
                page: (int) ($data['page'] ?? 1),
                pageSize: (int) ($data['page_size'] ?? 20),
            );
            $result['items'] = $this->enrichRows($result['items']);

            return ApiResponse::ok($result);
        }

        $resolved = $this->scope->selfEmployment($actor, $asOf, $employmentId)->employmentId;
        $items = $this->enrichRows($this->requests->forEmployment($resolved, $asOf));

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
            'page' => 1,
            'page_size' => max(1, count($items)),
        ]);
    }

    /**
     * Attach business display facts (employee number and department name) from
     * owning-module contracts; no People/Organization table is queried here.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function enrichRows(array $rows): array
    {
        $cache = [];

        foreach ($rows as &$row) {
            $employmentId = (int) ($row['employment_id'] ?? 0);
            $businessDate = (string) ($row['fromDate'] ?? '');
            $cache[$employmentId] ??= $this->employments->snapshot($employmentId, $businessDate !== '' ? $businessDate : '2026-01-01');
            $snapshot = $cache[$employmentId];
            $row['employeeNo'] = $snapshot?->employeeNo;
            $orgUnitId = $row['org_unit_id'] ?? $snapshot?->orgUnitId;
            $orgUnit = is_int($orgUnitId) ? $this->organization->orgUnit($orgUnitId) : null;
            $row['departmentName'] = $orgUnit?->name;
        }
        unset($row);

        return $rows;
    }

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'leaveTypeCode' => ['required', 'string', 'max:32'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
            'halfDay' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $employmentId = $this->scope->selfEmployment($actor, (string) $data['startDate'])->employmentId;
        $preview = $this->requests->preview(
            employmentId: $employmentId,
            leaveTypeCode: (string) $data['leaveTypeCode'],
            fromDate: (string) $data['startDate'],
            toDate: (string) $data['endDate'],
            halfDay: (bool) ($data['halfDay'] ?? false),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );

        return ApiResponse::ok([
            'workingDays' => (int) $preview['working_days'],
            'requestedDays' => $this->numericDisplay($preview['requested_days']),
            'availableBefore' => $this->numericDisplay($preview['available_before_days']),
            'availableAfter' => $this->numericDisplay($preview['available_after_days']),
            'unit' => (string) $preview['unit'],
            'conflicts' => $preview['conflicts'],
            'note' => (string) $preview['note'],
            'leaveTypeCode' => (string) $preview['leave_type_code'],
            'requestedMinutes' => (int) $preview['requested_minutes'],
            'availableBeforeMinutes' => (int) $preview['available_before_minutes'],
            'availableAfterMinutes' => (int) $preview['available_after_minutes'],
            'standardDayMinutes' => (int) $preview['standard_day_minutes'],
            'policyVersion' => (int) $preview['policy_version'],
            'policyVersionId' => (int) $preview['policy_version_id'],
        ]);
    }

    public function request(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'leaveTypeCode' => ['required', 'string', 'max:32'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
            'halfDay' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'attachment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Accepted only to be ignored, so an older client that still
            // sends it can never change the subject away from the session
            // employment.
            'employment_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $employmentId = $this->scope->selfEmployment($actor, (string) $data['startDate'])->employmentId;
        $payload = [
            'employment_id' => $employmentId,
            'leaveTypeCode' => (string) $data['leaveTypeCode'],
            'startDate' => (string) $data['startDate'],
            'endDate' => (string) $data['endDate'],
            'halfDay' => (bool) ($data['halfDay'] ?? false),
            'reason' => trim((string) $data['reason']),
            'attachment_reference' => isset($data['attachment_reference']) ? (string) $data['attachment_reference'] : null,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.request.submit',
                capability: 'time.leave.request',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.request.submitted',
            ),
            $payload,
            function () use ($actor, $payload): array {
                $body = $this->requests->request(
                    employmentId: (int) $payload['employment_id'],
                    userId: $actor->userId,
                    leaveTypeCode: (string) $payload['leaveTypeCode'],
                    fromDate: (string) $payload['startDate'],
                    toDate: (string) $payload['endDate'],
                    halfDay: (bool) $payload['halfDay'],
                    reason: (string) $payload['reason'],
                    attachmentReference: $payload['attachment_reference'],
                );

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created([
                        'caseId' => (int) $body['case_id'],
                        'caseNo' => (string) $body['case_no'],
                        'applicationStatus' => (string) $body['application_status'],
                        'effectStatus' => (string) $body['effect_status'],
                        'leaveTypeId' => (int) $body['leave_type_id'],
                        'leaveTypeCode' => (string) $body['leave_type_code'],
                        'policyVersionId' => (int) $body['policy_version_id'],
                        'requestedMinutes' => (int) $body['requested_minutes'],
                        'actorUserId' => (int) $body['actor_user_id'],
                        'subjectEmploymentId' => (int) $body['subject_employment_id'],
                        'projectionId' => (int) $body['projection_id'],
                        'replayed' => (bool) $body['replayed'],
                    ])->getData(true),
                    'resource_type' => 'leave_request',
                    'resource_id' => (string) $body['case_id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function cancel(Request $request, int $caseId): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'revoke' => ['sometimes', 'boolean'],
        ]);

        $projection = LeaveRequestProjection::query()->where('case_id', $caseId)->first();

        if ($projection === null) {
            throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
        }

        // Self cancel never delegates to time.leave.admin. Management
        // revocation is a separate command/capability on its own route.
        $this->scope->employment($actor, (int) $projection->employment_id, $projection->from_date->format('Y-m-d'));

        $payload = [
            'case_id' => $caseId,
            'reason' => (string) ($data['reason'] ?? ''),
            'revoke' => array_key_exists('revoke', $data) ? (bool) $data['revoke'] : null,
            'manager_override' => false,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.request.cancel',
                capability: 'time.leave.request.cancel',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.request.cancelled',
            ),
            $payload,
            function () use ($payload, $actor, $caseId): array {
                $body = $this->requests->cancel(
                    caseId: $caseId,
                    actorUserId: $actor->userId,
                    reason: (string) $payload['reason'] !== '' ? (string) $payload['reason'] : 'Leave request cancelled',
                    revoke: $payload['revoke'],
                    managerOverride: (bool) $payload['manager_override'],
                );

                return [
                    'http_status' => 200,
                    'body' => ApiResponse::ok([
                        'caseId' => (int) $body['case_id'],
                        'state' => (string) $body['state'],
                        'releasedMinutes' => (int) $body['released_minutes'],
                        'reversedMinutes' => (int) $body['reversed_minutes'],
                        'replayed' => (bool) $body['replayed'],
                        'applicationStatus' => $this->workflow->case($caseId)?->status,
                    ])->getData(true),
                    'resource_type' => 'leave_request',
                    'resource_id' => (string) $caseId,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function ledger(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null);
        $resolved = $this->scope->selfEmployment($actor, $asOf, $employmentId)->employmentId;

        $typeId = $request->query('leave_type_id');
        $typeCode = $request->query('leave_type');
        $scopedEmploymentId = $request->query('employment_id');

        if ($scopedEmploymentId !== null && (int) $scopedEmploymentId > 0 && (int) $scopedEmploymentId !== $resolved) {
            // Admin/manager drill-down only. Self cannot widen scope by putting
            // an employment id in a query string.
            if (! $actor->hasCapability('time.leave.admin')) {
                throw new NotFoundOrForbiddenException;
            }

            $resolved = $this->scope->employment($actor, (int) $scopedEmploymentId, $asOf)->employmentId;
        }

        if ($typeId !== null && $typeId !== '') {
            $type = LeaveType::query()->whereKey((int) $typeId)->first();
        } elseif (is_string($typeCode) && $typeCode !== '') {
            $type = LeaveType::query()->where('code', strtoupper($typeCode))->first();
        } else {
            throw new ValidationException(['leave_type' => ['A leave type code or id is required.']]);
        }

        if ($type === null) {
            throw new ValidationException(['leave_type' => ['The leave type does not exist in this tenant.']]);
        }

        $summary = $this->balances->forType($resolved, (int) $type->id, $asOf);
        $entries = LeaveLedgerEntry::query()
            ->where('employment_id', $resolved)
            ->where('leave_type_id', $type->id)
            ->where('effective_on', '<=', $asOf)
            ->orderBy('effective_on')
            ->orderBy('id')
            ->get();

        $running = 0;
        $balanceAfter = [];
        $payload = [];

        foreach ($entries as $entry) {
            $running += (int) $entry->quantity_minutes;
            $balanceAfter[] = $running;
            $payload[] = [
                'id' => (string) $entry->id,
                'occurredAt' => (string) $entry->recorded_at,
                'businessDate' => CarbonImmutable::parse((string) $entry->business_date)->format('Y-m-d'),
                'effectiveOn' => CarbonImmutable::parse((string) $entry->effective_on)->format('Y-m-d'),
                'entryType' => (string) $entry->account,
                'account' => (string) $entry->account,
                'quantity' => (int) $entry->quantity_minutes,
                'quantityMinutes' => (int) $entry->quantity_minutes,
                'balanceAfter' => $running,
                'reason' => (string) $entry->reason,
                'sourceType' => (string) $entry->source_type,
                'sourceId' => (string) $entry->source_id,
                'sourceCaseNo' => $entry->case_id !== null ? 'CASE-'.$entry->case_id : null,
                'caseId' => $entry->case_id !== null ? (int) $entry->case_id : null,
            ];
        }

        return ApiResponse::ok([
            'leave_type_id' => (int) $type->id,
            'leave_type_code' => (string) $type->code,
            'leave_type_name' => (string) $type->name,
            'unit' => (string) $type->unit,
            'as_of' => $asOf,
            'opening_minutes' => 0,
            'closing_minutes' => (int) $summary['balance'],
            'closing' => $summary['balance_days'],
            'standard_day_minutes' => (int) $summary['standard_day_minutes'],
            'balance_after' => $balanceAfter,
            'entries' => $payload,
            'summary' => $summary,
            'source_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function numericDisplay(mixed $value): int|float
    {
        $number = (float) $value;
        $rounded = round($number, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>
     */
    private function balancePayload(array $balance): array
    {
        $unit = (string) $balance['unit'];
        $standardDayMinutes = max(1, (int) $balance['standard_day_minutes']);
        $divisor = $unit === 'HOUR' ? 60 : $standardDayMinutes;
        $display = static function (int $minutes) use ($divisor): int|float {
            $value = abs($minutes) / $divisor;
            $rounded = round($value, 2);

            return floor($rounded) === $rounded ? (int) $rounded : $rounded;
        };

        return [
            'leaveTypeId' => (int) $balance['leave_type_id'],
            'leaveTypeCode' => (string) $balance['leave_type_code'],
            'leaveTypeName' => (string) $balance['leave_type_name'],
            'unit' => $unit,
            // Display values use the account unit. Canonical minute fields are
            // exposed separately below for calculations and reconciliation.
            'granted' => $display((int) $balance['granted']),
            'used' => $display((int) $balance['consumed']),
            'pending' => $display((int) $balance['pending']),
            'available' => $display((int) $balance['available']),
            'asOf' => (string) $balance['as_of'],
            'standardDayMinutes' => $standardDayMinutes,
            'grantedMinutes' => abs((int) $balance['granted']),
            'consumedMinutes' => abs((int) $balance['consumed']),
            'reservedMinutes' => abs((int) $balance['reserved']),
            'availableMinutes' => (int) $balance['available'],
            'balanceMinutes' => (int) $balance['balance_minutes'],
            'balanceDays' => $balance['balance_days'],
        ];
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}

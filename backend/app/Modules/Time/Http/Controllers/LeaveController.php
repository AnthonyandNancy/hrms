<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveRequestService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveRequestService $requests,
        private readonly WorkflowCasePort $workflow,
    ) {}

    public function balances(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $employmentId ?? (int) ($actor->employmentId ?? 0);
        $asOf = $this->scope->validateDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null, 'as_of', false);
        $this->scope->employment($actor, $employmentId, $asOf);

        $items = array_map(
            fn (array $balance): array => $this->balancePayload($balance),
            $this->balances->outstanding($actor->tenantId, $employmentId, $asOf),
        );

        return ApiResponse::ok($items);
    }

    public function requests(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $employmentId ?? (int) ($actor->employmentId ?? 0);
        $asOf = $this->scope->validateDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null, 'as_of', false);
        $this->scope->employment($actor, $employmentId, $asOf);

        $items = [];

        foreach ($this->requests->forEmployment($actor->tenantId, $employmentId, $asOf) as $projection) {
            $case = $this->workflow->case($actor->tenantId, (int) $projection['case_id']);

            if ($case === null) {
                continue;
            }

            $items[] = [
                'id' => $case->id,
                'caseId' => $case->id,
                'caseNo' => $case->caseNo,
                'title' => $case->title,
                'type' => $case->type,
                'applicationStatus' => $case->status,
                'effectStatus' => $case->effectStatus,
                'currentNode' => $case->currentNode,
                'dueAt' => $case->dueAt,
                'isMine' => $case->requesterUserId === $actor->userId,
                'isAwaitingMe' => false,
                'leaveTypeId' => (int) $projection['leave_type_id'],
                'fromDate' => (string) $projection['from_date'],
                'toDate' => (string) $projection['to_date'],
                'minutes' => (int) $projection['minutes'],
                'state' => (string) $projection['state'],
            ];
        }

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
        ]);
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
            'employment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $employmentId = (int) ($data['employment_id'] ?? $actor->employmentId ?? 0);
        $this->scope->employment($actor, $employmentId, (string) $data['startDate']);

        $preview = $this->requests->preview(
            tenantId: $actor->tenantId,
            employmentId: $employmentId,
            leaveTypeCode: (string) $data['leaveTypeCode'],
            fromDate: (string) $data['startDate'],
            toDate: (string) $data['endDate'],
            halfDay: (bool) ($data['halfDay'] ?? false),
        );

        return ApiResponse::ok([
            'workingDays' => (int) $preview['working_days'],
            'requestedDays' => (float) $preview['requested_days'],
            'availableBefore' => (float) $preview['available_before_days'],
            'availableAfter' => (float) $preview['available_after_days'],
            'unit' => (string) $preview['unit'],
            'conflicts' => $preview['conflicts'],
            'note' => (string) $preview['note'],
            'leaveTypeCode' => (string) $preview['leave_type_code'],
            'requestedMinutes' => (int) $preview['requested_minutes'],
            'availableBeforeMinutes' => (int) $preview['available_before_minutes'],
            'availableAfterMinutes' => (int) $preview['available_after_minutes'],
            'standardDayMinutes' => (int) $preview['standard_day_minutes'],
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
            'employment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $employmentId = (int) ($data['employment_id'] ?? $actor->employmentId ?? 0);
        $this->scope->employment($actor, $employmentId, (string) $data['startDate']);

        $result = $this->requests->request(
            tenantId: $actor->tenantId,
            employmentId: $employmentId,
            userId: $actor->userId,
            leaveTypeCode: (string) $data['leaveTypeCode'],
            fromDate: (string) $data['startDate'],
            toDate: (string) $data['endDate'],
            halfDay: (bool) ($data['halfDay'] ?? false),
            reason: (string) $data['reason'],
        );

        return ApiResponse::ok([
            'caseId' => (int) $result['case_id'],
            'caseNo' => (string) $result['case_no'],
            'applicationStatus' => (string) $result['application_status'],
            'effectStatus' => $result['effect_status'] !== 'NONE' ? (string) $result['effect_status'] : null,
            'leaveTypeId' => (int) $result['leave_type_id'],
            'requestedMinutes' => (int) $result['requested_minutes'],
        ]);
    }

    public function cancel(Request $request, int $caseId): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $case = $this->workflow->case($actor->tenantId, $caseId);

        if ($case === null || $case->subjectId === null) {
            throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
        }

        $this->scope->employment($actor, $case->subjectId, $case->businessDate);

        $result = $this->requests->cancel(
            tenantId: $actor->tenantId,
            caseId: $caseId,
            actorUserId: $actor->userId,
            reason: (string) ($data['reason'] ?? 'Leave request cancelled'),
        );

        return ApiResponse::ok([
            'caseId' => (int) $result['case_id'],
            'state' => (string) $result['state'],
            'releasedMinutes' => (int) $result['released_minutes'],
            'replayed' => (bool) $result['replayed'],
            'applicationStatus' => $this->workflow->case($actor->tenantId, $caseId)?->status,
        ]);
    }

    /**
     * Drill-down endpoint. Accepts either the brief's employment path plus
     * leave_type_id or the frontend's self-scoped leave_type code.
     */
    public function ledger(Request $request, ?int $employmentId = null): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $employmentId ?? (int) ($actor->employmentId ?? 0);
        $asOf = $this->scope->validateDate($request->query('as_of') !== null ? (string) $request->query('as_of') : null, 'as_of', false);
        $this->scope->employment($actor, $employmentId, $asOf);

        $typeId = $request->query('leave_type_id');
        $typeCode = $request->query('leave_type');

        if ($typeId !== null && $typeId !== '') {
            $type = LeaveType::query()
                ->where('tenant_id', $actor->tenantId)
                ->whereKey((int) $typeId)
                ->first();
        } elseif (is_string($typeCode) && $typeCode !== '') {
            $type = LeaveType::query()
                ->where('tenant_id', $actor->tenantId)
                ->where('code', strtoupper($typeCode))
                ->first();
        } else {
            throw new ValidationException(['leave_type' => ['A leave type code or id is required.']]);
        }

        if ($type === null) {
            throw new ValidationException(['leave_type' => ['The leave type does not exist in this tenant.']]);
        }

        $summary = $this->balances->forType($actor->tenantId, $employmentId, (int) $type->id, $asOf);
        $entries = LeaveLedgerEntry::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('employment_id', $employmentId)
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
                'businessDate' => \Carbon\CarbonImmutable::parse((string) $entry->business_date)->format('Y-m-d'),
                'effectiveOn' => \Carbon\CarbonImmutable::parse((string) $entry->effective_on)->format('Y-m-d'),
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
            'employment_id' => $employmentId,
            'as_of' => $asOf,
            'opening_minutes' => 0,
            'closing_minutes' => (int) $summary['balance'],
            'summary' => $summary,
            'entries' => $payload,
            'balance_after' => $balanceAfter,
            'leaveTypeName' => (string) $type->name,
            'opening' => 0,
            'closing' => (int) $summary['balance_days'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>
     */
    private function balancePayload(array $balance): array
    {
        $standard = max(1, (int) $balance['standard_day_minutes']);
        $granted = (int) $balance['granted'];
        $consumed = (int) $balance['consumed'];
        $reserved = (int) $balance['reserved'];

        return [
            'leaveTypeCode' => (string) $balance['leave_type_code'],
            'leaveTypeName' => (string) ($balance['leave_type_name'] ?? $balance['leave_type_code']),
            'unit' => (string) $balance['unit'],
            'granted' => (float) \App\Modules\Time\Services\QuantityUnit::toDisplayDays($granted, $standard),
            'used' => (float) \App\Modules\Time\Services\QuantityUnit::toDisplayDays($consumed, $standard),
            'pending' => (float) \App\Modules\Time\Services\QuantityUnit::toDisplayDays($reserved, $standard),
            'available' => (float) \App\Modules\Time\Services\QuantityUnit::toDisplayDays((int) $balance['available'], $standard),
            'expiresOn' => null,
            'leaveTypeId' => (int) $balance['leave_type_id'],
            'asOf' => (string) $balance['as_of'],
            'grantedMinutes' => $granted,
            'reservedMinutes' => $reserved,
            'consumedMinutes' => $consumed,
            'releasedMinutes' => (int) $balance['released'],
            'expiredMinutes' => (int) $balance['expired'],
            'adjustedMinutes' => (int) $balance['adjusted'],
            'reversedMinutes' => (int) $balance['reversed'],
            'availableMinutes' => (int) $balance['available'],
            'balanceMinutes' => (int) $balance['balance_minutes'],
            'balanceDays' => (string) $balance['balance_days'],
            'standardDayMinutes' => $standard,
            'unexplainedDifference' => (int) $balance['unexplained_difference'],
        ];
    }
}

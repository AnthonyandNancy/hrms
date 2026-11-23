<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave request application service. RESERVE happens at submit; the case owns
 * approval state and the ledger owns the balance. Cancellation RELEASEs the
 * reservation once and is idempotent.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly WorkflowCasePort $workflow,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveAccountService $accounts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        int $tenantId,
        int $employmentId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
    ): array {
        BusinessDate::parse($fromDate);
        BusinessDate::parse($toDate);

        if ($toDate < $fromDate) {
            throw new ValidationException(['to_date' => ['The end date must not precede the start date.']]);
        }

        if ($halfDay && $fromDate !== $toDate) {
            throw new ValidationException(['half_day' => ['A half-day request must start and end on the same date.']]);
        }

        $type = $this->type($tenantId, $leaveTypeCode);
        $standardDay = $this->accounts->standardDayMinutes($tenantId, $employmentId, $fromDate);
        $requested = $this->requestedMinutes($tenantId, $employmentId, $type, $fromDate, $toDate, $halfDay);
        $available = $this->balances->available($tenantId, $employmentId, (int) $type->id, $fromDate);
        $handover = [];

        if (! $type->allow_negative && $available < $requested) {
            $handover[] = [
                'code' => 'INSUFFICIENT_BALANCE',
                'severity' => 'BLOCKING',
                'message' => 'The requested leave exceeds the available balance.',
            ];
        }

        $standardDayDivisor = max(1, $standardDay);

        return [
            'leave_type_id' => (int) $type->id,
            'leave_type_code' => (string) $type->code,
            'unit' => (string) $type->unit,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'half_day' => $halfDay,
            'working_days' => $this->workingDays($fromDate, $toDate),
            'requested_minutes' => $requested,
            'requested_days' => $type->unit === 'DAY' ? QuantityUnit::toDisplayDays($requested, $standardDayDivisor) : '0.00',
            'available_before_minutes' => $available,
            'available_before_days' => QuantityUnit::toDisplayDays($available, $standardDayDivisor),
            'available_after_minutes' => $available - $requested,
            'available_after_days' => QuantityUnit::toDisplayDays($available - $requested, $standardDayDivisor),
            'standard_day_minutes' => $standardDayDivisor,
            'conflicts' => $handover,
            'note' => $handover === []
                ? 'Balance is reserved at submit and settled on approval.'
                : 'The request cannot be submitted until the balance or dates are adjusted.',
        ];
    }

    /**
     * @return array{case_id: int, case_no: string, application_status: string, effect_status: string, leave_type_id: int, requested_minutes: int, replayed: bool}
     */
    public function request(
        int $tenantId,
        int $employmentId,
        int $userId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
        string $reason,
    ): array {
        BusinessDate::parse($fromDate);
        BusinessDate::parse($toDate);

        if ($toDate < $fromDate) {
            throw new ValidationException(['to_date' => ['The end date must not precede the start date.']]);
        }

        if ($halfDay && $fromDate !== $toDate) {
            throw new ValidationException(['half_day' => ['A half-day request must start and end on the same date.']]);
        }

        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A leave request reason is required.']]);
        }

        $type = $this->type($tenantId, $leaveTypeCode);
        $this->accounts->ensureAccount($tenantId, $employmentId, (int) $type->id, $fromDate);
        $minutes = $this->requestedMinutes($tenantId, $employmentId, $type, $fromDate, $toDate, $halfDay);

        $existing = LeaveRequestProjection::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $type->id)
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->whereIn('state', ['SUBMITTED', 'APPROVED'])
            ->first();

        if ($existing !== null) {
            throw new ConflictException(
                'An overlapping leave request already exists.',
                ['reason' => 'LEAVE_REQUEST_OVERLAP', 'case_id' => (int) $existing->case_id],
            );
        }

        return DB::transaction(function () use ($tenantId, $employmentId, $userId, $type, $fromDate, $toDate, $minutes, $halfDay, $reason): array {
            $caseId = $this->workflow->createCase(
                tenantId: $tenantId,
                requesterUserId: $userId,
                type: 'LEAVE_REQUEST',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'title' => 'Leave request',
                    'employment_id' => $employmentId,
                    'leave_type_id' => (int) $type->id,
                    'leave_type_code' => (string) $type->code,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'half_day' => $halfDay,
                    'minutes' => $minutes,
                    'reason' => $reason,
                ],
                businessDate: $fromDate,
            );

            $this->ledger->reserve(
                tenantId: $tenantId,
                employmentId: $employmentId,
                leaveTypeId: (int) $type->id,
                minutes: $minutes,
                businessDate: $fromDate,
                sourceType: 'LEAVE_REQUEST',
                sourceId: (string) $caseId,
                reason: $reason,
                caseId: $caseId,
            );

            $projection = LeaveRequestProjection::query()->create([
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'leave_type_id' => (int) $type->id,
                'case_id' => $caseId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'minutes' => $minutes,
                'state' => 'SUBMITTED',
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $case = $this->workflow->case($tenantId, $caseId);

            return [
                'case_id' => $caseId,
                'case_no' => $case !== null ? 'CASE-'.$caseId : 'CASE-'.$caseId,
                'application_status' => $case->status ?? 'SUBMITTED',
                'effect_status' => $case->effectStatus ?? 'NONE',
                'leave_type_id' => (int) $type->id,
                'requested_minutes' => $minutes,
                'replayed' => false,
            ];
        });
    }

    /**
     * Release the reservation exactly once; repeated cancellation is a no-op.
     *
     * @return array{case_id: int, state: string, released_minutes: int, replayed: bool}
     */
    public function cancel(int $tenantId, int $caseId, int $actorUserId, string $reason): array
    {
        return DB::transaction(function () use ($tenantId, $caseId, $actorUserId, $reason): array {
            $case = $this->workflow->case($tenantId, $caseId);

            if ($case === null || $case->requesterUserId !== $actorUserId) {
                throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            $projection = LeaveRequestProjection::query()
                ->where('tenant_id', $tenantId)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                throw new ConflictException('The leave request projection was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            if (in_array((string) $projection->state, ['CANCELLED', 'RELEASED'], true)) {
                return [
                    'case_id' => $caseId,
                    'state' => (string) $projection->state,
                    'released_minutes' => 0,
                    'replayed' => true,
                ];
            }

            $this->ledger->release(
                tenantId: $tenantId,
                employmentId: $projection->employment_id,
                leaveTypeId: $projection->leave_type_id,
                minutes: (int) $projection->minutes,
                businessDate: $projection->from_date->format('Y-m-d'),
                sourceType: 'LEAVE_REQUEST',
                sourceId: (string) $caseId,
                reason: $reason !== '' ? $reason : 'Leave request cancelled',
                caseId: $caseId,
            );

            $this->workflow->requestCancel($tenantId, $caseId, $actorUserId, $reason);

            $projection->forceFill(['state' => 'CANCELLED', 'updated_at' => $this->clock->now()])->save();

            return [
                'case_id' => $caseId,
                'state' => 'CANCELLED',
                'released_minutes' => (int) $projection->minutes,
                'replayed' => false,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployment(int $tenantId, int $employmentId, ?string $asOf = null): array
    {
        $asOf ??= $this->clock->now()->toDateString();

        return LeaveRequestProjection::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('from_date', '<=', $asOf)
            ->orderByDesc('id')
            ->get()
            ->map(fn (LeaveRequestProjection $projection): array => [
                'case_id' => (int) $projection->case_id,
                'leave_type_id' => (int) $projection->leave_type_id,
                'from_date' => CarbonImmutable::parse((string) $projection->from_date)->format('Y-m-d'),
                'to_date' => CarbonImmutable::parse((string) $projection->to_date)->format('Y-m-d'),
                'minutes' => (int) $projection->minutes,
                'state' => (string) $projection->state,
            ])
            ->all();
    }

    private function requestedMinutes(
        int $tenantId,
        int $employmentId,
        LeaveType $type,
        string $fromDate,
        string $toDate,
        bool $halfDay,
    ): int {
        if ($type->unit === 'HOUR') {
            $minutes = $this->workingDays($fromDate, $toDate) * 60;

            return $halfDay ? 30 : max(1, $minutes);
        }

        $standardDay = $this->accounts->standardDayMinutes($tenantId, $employmentId, $fromDate);
        $days = $this->workingDays($fromDate, $toDate);

        return ($halfDay ? 1 : max(1, $days)) * $standardDay;
    }

    private function workingDays(string $fromDate, string $toDate): int
    {
        $from = CarbonImmutable::parse($fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($toDate, 'UTC')->startOfDay();

        return max(1, (int) $from->diffInDays($to) + 1);
    }

    private function type(int $tenantId, string $code): LeaveType
    {
        $type = LeaveType::query()
            ->where('tenant_id', $tenantId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        if ($type === null) {
            throw new ValidationException(['leave_type' => ['The leave type does not exist in this tenant.']]);
        }

        return $type;
    }
}

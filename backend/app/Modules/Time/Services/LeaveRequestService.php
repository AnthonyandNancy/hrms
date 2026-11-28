<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave request application service.
 *
 * Submit reserves integer minutes, the Workflow case owns approval, and the
 * ledger owns balances. Self and on-behalf are separate entry points with
 * different controllers/capabilities; this service still receives the subject
 * explicitly so a client-supplied employment id can never be trusted by the
 * self controller.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly WorkflowCasePort $workflow,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveAccountService $accounts,
        private readonly BusinessCalendar $calendar,
        private readonly LeavePolicyService $policies,
        private readonly PayrollFreezeGuard $freezeGuard,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        int $employmentId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
        ?string $reason = null,
    ): array {
        $this->assertDates($fromDate, $toDate, $halfDay);

        $type = $this->type($leaveTypeCode);
        $policy = $this->policy($type, $fromDate, true);
        $standardDay = $this->accounts->standardDayMinutes($employmentId, $fromDate);
        $workingDays = $this->workingDays($fromDate, $toDate);
        $requested = $this->requestedMinutes($employmentId, $type, $workingDays, $halfDay);
        $available = $this->balances->available($employmentId, (int) $type->id, $fromDate);
        $conflicts = [];

        if (! $type->allow_negative && $available < $requested) {
            $conflicts[] = [
                'code' => 'INSUFFICIENT_BALANCE',
                'severity' => 'BLOCKING',
                'message' => 'The requested leave exceeds the available balance.',
            ];
        }

        $ruleConflicts = $this->ruleConflicts(
            policy: $policy,
            leaveType: $type,
            employmentId: $employmentId,
            fromDate: $fromDate,
            toDate: $toDate,
            workingDays: $workingDays,
            halfDay: $halfDay,
            reason: $reason,
        );

        $conflicts = [...$conflicts, ...$ruleConflicts];
        $overlap = $this->overlap($employmentId, $fromDate, $toDate);

        if ($overlap !== null) {
            $conflicts[] = [
                'code' => 'LEAVE_REQUEST_OVERLAP',
                'severity' => 'BLOCKING',
                'message' => 'An overlapping leave request already exists.',
                'case_id' => (int) $overlap->case_id,
            ];
        }

        $standardDayDivisor = max(1, $standardDay);
        $blocking = array_values(array_filter(
            $conflicts,
            static fn (array $conflict): bool => ($conflict['severity'] ?? '') === 'BLOCKING',
        ));

        return [
            'leave_type_id' => (int) $type->id,
            'leave_type_code' => (string) $type->code,
            'unit' => (string) $type->unit,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'half_day' => $halfDay,
            'working_days' => $workingDays,
            'requested_minutes' => $requested,
            'requested_days' => $type->unit === 'DAY'
                ? QuantityUnit::toDisplayDays($requested, $standardDayDivisor)
                : (float) ($requested / 60),
            'available_before_minutes' => $available,
            'available_before_days' => QuantityUnit::toDisplayDays($available, $standardDayDivisor),
            'available_after_minutes' => $available - $requested,
            'available_after_days' => QuantityUnit::toDisplayDays($available - $requested, $standardDayDivisor),
            'standard_day_minutes' => $standardDayDivisor,
            'policy_version_id' => (int) $policy->id,
            'policy_version' => (int) $policy->version,
            'conflicts' => $conflicts,
            'note' => $blocking === []
                ? 'Balance is reserved at submit and settled on approval.'
                : 'The request cannot be submitted until the highlighted issues are adjusted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(
        int $employmentId,
        int $userId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
        string $reason,
        ?int $orgUnitId = null,
        ?string $attachmentReference = null,
    ): array {
        return $this->submit(
            employmentId: $employmentId,
            actorUserId: $userId,
            leaveTypeCode: $leaveTypeCode,
            fromDate: $fromDate,
            toDate: $toDate,
            halfDay: $halfDay,
            reason: $reason,
            onBehalf: false,
            orgUnitId: $orgUnitId,
            attachmentReference: $attachmentReference,
        );
    }

    /**
     * On-behalf is a distinct command. The caller (route capability + scope)
     * has already proven the actor may manage the explicit subject.
     *
     * @return array<string, mixed>
     */
    public function requestOnBehalf(
        int $employmentId,
        int $actorUserId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
        string $reason,
        ?int $orgUnitId = null,
        ?string $attachmentReference = null,
    ): array {
        if ($actorUserId <= 0) {
            throw new ValidationException(['actor' => ['A resolved actor is required.']]);
        }

        return $this->submit(
            employmentId: $employmentId,
            actorUserId: $actorUserId,
            leaveTypeCode: $leaveTypeCode,
            fromDate: $fromDate,
            toDate: $toDate,
            halfDay: $halfDay,
            reason: $reason,
            onBehalf: true,
            orgUnitId: $orgUnitId,
            attachmentReference: $attachmentReference,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(
        int $employmentId,
        int $actorUserId,
        string $leaveTypeCode,
        string $fromDate,
        string $toDate,
        bool $halfDay,
        string $reason,
        bool $onBehalf,
        ?int $orgUnitId,
        ?string $attachmentReference,
    ): array {
        $this->assertDates($fromDate, $toDate, $halfDay);

        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A leave request reason is required.']]);
        }

        $type = $this->type($leaveTypeCode);
        $policy = $this->policy($type, $fromDate, false);
        $workingDays = $this->workingDays($fromDate, $toDate);
        $ruleConflicts = $this->ruleConflicts(
            policy: $policy,
            leaveType: $type,
            employmentId: $employmentId,
            fromDate: $fromDate,
            toDate: $toDate,
            workingDays: $workingDays,
            halfDay: $halfDay,
            reason: $reason,
            attachmentReference: $attachmentReference,
        );

        if ($ruleConflicts !== []) {
            throw new ValidationException(
                ['leave' => array_map(static fn (array $conflict): string => (string) $conflict['message'], $ruleConflicts)],
                'The leave request violates the effective leave policy.',
                ['reason' => (string) $ruleConflicts[0]['code'], 'conflicts' => $ruleConflicts],
            );
        }

        $this->accounts->ensureAccount($employmentId, (int) $type->id, $fromDate);
        $minutes = $this->requestedMinutes($employmentId, $type, $workingDays, $halfDay);

        return DB::transaction(function () use (
            $employmentId,
            $actorUserId,
            $type,
            $policy,
            $fromDate,
            $toDate,
            $minutes,
            $halfDay,
            $reason,
            $onBehalf,
            $orgUnitId,
        ): array {
            foreach (PayrollFreezeGuard::periodsForRange($fromDate, $toDate) as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            // Serialize requests for one leave account before checking the
            // overlap window. The projection lock alone is insufficient when
            // the caller has not yet created a row.
            $this->accounts->ensureAccount($employmentId, (int) $type->id, $fromDate);
            $this->ledger->assertAccountLockable($employmentId, (int) $type->id);

            $overlap = $this->overlap($employmentId, $fromDate, $toDate, true);

            if ($overlap !== null) {
                throw new ConflictException(
                    'An overlapping leave request already exists.',
                    ['reason' => 'LEAVE_REQUEST_OVERLAP', 'case_id' => (int) $overlap->case_id],
                );
            }

            $caseId = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'LEAVE_REQUEST',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'title' => $onBehalf ? 'HR leave request' : 'Leave request',
                    'employment_id' => $employmentId,
                    'actor_user_id' => $actorUserId,
                    'subject_employment_id' => $employmentId,
                    'on_behalf' => $onBehalf,
                    'leave_type_id' => (int) $type->id,
                    'leave_type_code' => (string) $type->code,
                    'leave_policy_version_id' => (int) $policy->id,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'half_day' => $halfDay,
                    'minutes' => $minutes,
                    'reason' => $reason,
                ],
                businessDate: $fromDate,
            );

            $postedOn = $this->clock->now()->toDateString();

            $this->ledger->reserve(
                employmentId: $employmentId,
                leaveTypeId: (int) $type->id,
                minutes: $minutes,
                businessDate: $postedOn,
                sourceType: 'LEAVE_REQUEST',
                sourceId: (string) $caseId,
                reason: $reason,
                caseId: $caseId,
                effectiveOn: $postedOn,
            );

            $projection = LeaveRequestProjection::query()->create([
                'employment_id' => $employmentId,
                'actor_user_id' => $actorUserId,
                'org_unit_id' => $orgUnitId,
                'leave_type_id' => (int) $type->id,
                'leave_policy_version_id' => (int) $policy->id,
                'case_id' => $caseId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'minutes' => $minutes,
                'state' => 'SUBMITTED',
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $case = $this->workflow->case($caseId);
            $state = $case?->isApproved() === true ? 'APPROVED' : 'SUBMITTED';

            $this->audit->record(
                $actorUserId,
                $onBehalf ? 'time.leave.request.on_behalf_submitted' : 'time.leave.request.submitted',
                'leave_request',
                $caseId,
                after: [
                    'case_id' => $caseId,
                    'actor_user_id' => $actorUserId,
                    'subject_employment_id' => $employmentId,
                    'leave_type_id' => (int) $type->id,
                    'policy_version_id' => (int) $policy->id,
                    'minutes' => $minutes,
                    'state' => $state,
                ],
            );

            return [
                'case_id' => $caseId,
                'case_no' => $case->caseNo !== '' ? $case->caseNo : 'CASE-'.$caseId,
                'application_status' => $case->status ?? 'SUBMITTED',
                'effect_status' => $case->effectStatus ?? 'NONE',
                'leave_type_id' => (int) $type->id,
                'leave_type_code' => (string) $type->code,
                'policy_version_id' => (int) $policy->id,
                'requested_minutes' => $minutes,
                'actor_user_id' => $actorUserId,
                'subject_employment_id' => $employmentId,
                'projection_id' => (int) $projection->id,
                'replayed' => false,
            ];
        });
    }

    /**
     * Cancel/revoke state machine.
     *
     * SUBMITTED -> RELEASE + workflow withdraw (CANCELLED).
     * APPROVED/CONSUMED -> freeze guard, REVERSE consumption and reserved hold
     * (REVOKED). Replaying the same method on a terminal projection is a no-op.
     *
     * @return array{case_id: int, state: string, released_minutes: int, reversed_minutes: int, replayed: bool}
     */
    public function cancel(
        int $caseId,
        int $actorUserId,
        string $reason,
        ?bool $revoke = null,
        bool $managerOverride = false,
    ): array {
        return DB::transaction(function () use ($caseId, $actorUserId, $reason, $revoke, $managerOverride): array {
            /** @var LeaveRequestProjection|null $projection */
            $projection = LeaveRequestProjection::query()
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            $case = $this->workflow->case($caseId);
            $state = strtoupper((string) $projection->state);
            $shouldRevoke = $revoke ?? in_array($state, ['APPROVED', 'CONSUMED', 'REVOKED'], true);

            if (in_array($state, ['CANCELLED', 'RELEASED'], true) && ! $shouldRevoke) {
                return [
                    'case_id' => $caseId,
                    'state' => $state,
                    'released_minutes' => 0,
                    'reversed_minutes' => 0,
                    'replayed' => true,
                ];
            }

            if ($state === 'REVOKED' && $shouldRevoke) {
                return [
                    'case_id' => $caseId,
                    'state' => $state,
                    'released_minutes' => 0,
                    'reversed_minutes' => 0,
                    'replayed' => true,
                ];
            }

            if (! $managerOverride && $case !== null && $case->requesterUserId !== $actorUserId) {
                throw new NotFoundOrForbiddenException;
            }

            $periods = PayrollFreezeGuard::periodsForRange(
                $projection->from_date->format('Y-m-d'),
                $projection->to_date->format('Y-m-d'),
            );

            foreach ($periods as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            if ($shouldRevoke) {
                $this->freezeGuard->assertNotFrozen((int) $projection->employment_id, $periods);
            }

            $businessDate = $this->clock->now()->toDateString();
            $minutes = (int) $projection->minutes;
            $released = 0;
            $reversed = 0;

            if ($shouldRevoke) {
                $consumed = $this->consumedMinutes($projection);

                if ($consumed > 0) {
                    $this->ledger->reverse(
                        employmentId: (int) $projection->employment_id,
                        leaveTypeId: (int) $projection->leave_type_id,
                        minutes: $consumed,
                        businessDate: $businessDate,
                        sourceType: 'LEAVE_REVOKE',
                        sourceId: (string) $caseId,
                        reason: $reason !== '' ? $reason : 'Leave revoked',
                        targetAccount: 'CONSUME',
                        caseId: $caseId,
                    );
                    $reversed = $consumed;
                }

                // If an approval consumed the reservation, CONSUME already
                // released it. Only release a still-outstanding hold.
                if ($consumed === 0) {
                    $this->ledger->release(
                        employmentId: (int) $projection->employment_id,
                        leaveTypeId: (int) $projection->leave_type_id,
                        minutes: $minutes,
                        businessDate: $businessDate,
                        sourceType: 'LEAVE_REQUEST',
                        sourceId: (string) $caseId,
                        reason: $reason !== '' ? $reason : 'Leave revoked',
                        caseId: $caseId,
                    );
                    $released = $minutes;
                }

                if ($case !== null && in_array($case->status, ['SUBMITTED', 'IN_REVIEW', 'DRAFT'], true)) {
                    $this->workflow->requestCancel($caseId, $actorUserId, $reason);
                }

                $projection->forceFill(['state' => 'REVOKED', 'updated_at' => $this->clock->now()])->save();
                $newState = 'REVOKED';
            } else {
                $this->ledger->release(
                    employmentId: (int) $projection->employment_id,
                    leaveTypeId: (int) $projection->leave_type_id,
                    minutes: $minutes,
                    businessDate: $businessDate,
                    sourceType: 'LEAVE_REQUEST',
                    sourceId: (string) $caseId,
                    reason: $reason !== '' ? $reason : 'Leave request cancelled',
                    caseId: $caseId,
                );
                $released = $minutes;

                if ($case !== null && in_array($case->status, ['SUBMITTED', 'IN_REVIEW', 'DRAFT'], true)) {
                    $this->workflow->requestCancel($caseId, $actorUserId, $reason);
                }

                $projection->forceFill(['state' => 'CANCELLED', 'updated_at' => $this->clock->now()])->save();
                $newState = 'CANCELLED';
            }

            $this->audit->record(
                $actorUserId,
                $shouldRevoke ? 'time.leave.request.revoked' : 'time.leave.request.cancelled',
                'leave_request',
                $caseId,
                before: ['state' => $state],
                after: [
                    'state' => $newState,
                    'actor_user_id' => $actorUserId,
                    'subject_employment_id' => (int) $projection->employment_id,
                    'released_minutes' => $released,
                    'reversed_minutes' => $reversed,
                ],
            );

            return [
                'case_id' => $caseId,
                'state' => $newState,
                'released_minutes' => $released,
                'reversed_minutes' => $reversed,
                'replayed' => false,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployment(int $employmentId, ?string $asOf = null, ?string $toDate = null): array
    {
        $asOf ??= $this->clock->now()->toDateString();

        $query = LeaveRequestProjection::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('id');

        if ($toDate !== null && $toDate !== '') {
            $query->where('to_date', '>=', $toDate);
        }

        return $query->get()
            ->map(fn (LeaveRequestProjection $projection): array => $this->projectionRow($projection))
            ->all();
    }

    /**
     * Scoped management listing used by the HR ledger tab.
     *
     * @param  list<int>  $scopeEmploymentIds
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function scopedRequests(array $scopeEmploymentIds, array $filters, int $page, int $pageSize): array
    {
        $query = LeaveRequestProjection::query();

        if ($scopeEmploymentIds !== []) {
            $query->whereIn('employment_id', $scopeEmploymentIds);
        }

        foreach ([
            'employment_id' => 'employment_id',
            'leave_type_id' => 'leave_type_id',
            'org_unit_id' => 'org_unit_id',
            'state' => 'state',
        ] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }

        if (isset($filters['from']) && (string) $filters['from'] !== '') {
            $query->where('to_date', '>=', (string) $filters['from']);
        }

        if (isset($filters['to']) && (string) $filters['to'] !== '') {
            $query->where('from_date', '<=', (string) $filters['to']);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        return [
            'items' => $rows->map(fn (LeaveRequestProjection $projection): array => $this->projectionRow($projection))->all(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectionRow(LeaveRequestProjection $projection): array
    {
        $case = $this->workflow->case((int) $projection->case_id);

        return [
            'id' => (int) $projection->id,
            'caseId' => (int) $projection->case_id,
            'caseNo' => $case !== null ? $case->caseNo : 'CASE-'.$projection->case_id,
            'title' => $case !== null ? $case->title : 'Leave request',
            'applicationStatus' => $case !== null ? $case->status : 'UNKNOWN',
            'effectStatus' => $case?->effectStatus,
            'currentNode' => $case?->currentNode,
            'dueAt' => $case?->dueAt,
            'employment_id' => (int) $projection->employment_id,
            'actor_user_id' => $projection->actor_user_id !== null ? (int) $projection->actor_user_id : null,
            'subject_employment_id' => (int) $projection->employment_id,
            'org_unit_id' => $projection->org_unit_id !== null ? (int) $projection->org_unit_id : null,
            'leaveTypeId' => (int) $projection->leave_type_id,
            'leave_policy_version_id' => $projection->leave_policy_version_id !== null
                ? (int) $projection->leave_policy_version_id
                : null,
            'fromDate' => $projection->from_date->format('Y-m-d'),
            'toDate' => $projection->to_date->format('Y-m-d'),
            'minutes' => (int) $projection->minutes,
            'state' => (string) $projection->state,
        ];
    }

    private function assertDates(string $fromDate, string $toDate, bool $halfDay): void
    {
        BusinessDate::parse($fromDate);
        BusinessDate::parse($toDate);

        if ($toDate < $fromDate) {
            throw new ValidationException(['to_date' => ['The end date must not precede the start date.']]);
        }

        if ($halfDay && $fromDate !== $toDate) {
            throw new ValidationException(['half_day' => ['A half-day request must start and end on the same date.']]);
        }
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function ruleConflicts(
        \App\Modules\Time\Models\LeavePolicyVersion $policy,
        LeaveType $leaveType,
        int $employmentId,
        string $fromDate,
        string $toDate,
        int $workingDays,
        bool $halfDay,
        ?string $reason,
        ?string $attachmentReference = null,
    ): array {
        $conflicts = [];
        $today = $this->clock->now()->toDateString();
        $minNoticeDays = (int) $policy->min_notice_days;

        if ($minNoticeDays > 0) {
            $requiredFrom = CarbonImmutable::parse($today, 'UTC')->addDays($minNoticeDays)->format('Y-m-d');

            if ($fromDate < $requiredFrom) {
                $conflicts[] = [
                    'code' => 'LEAVE_MIN_NOTICE_VIOLATION',
                    'severity' => 'BLOCKING',
                    'message' => "This leave type requires at least {$minNoticeDays} day(s) of advance notice.",
                ];
            }
        }

        if ($policy->max_consecutive_days !== null && $workingDays > (int) $policy->max_consecutive_days) {
            $conflicts[] = [
                'code' => 'LEAVE_MAX_CONSECUTIVE_EXCEEDED',
                'severity' => 'BLOCKING',
                'message' => 'The request exceeds the maximum consecutive leave days for this type.',
            ];
        }

        if ($halfDay && ! (bool) $policy->allow_half_day) {
            $conflicts[] = [
                'code' => 'LEAVE_HALF_DAY_NOT_ALLOWED',
                'severity' => 'BLOCKING',
                'message' => 'Half-day leave is not allowed by the effective policy.',
            ];
        }

        $attachmentThreshold = $policy->requires_attachment_over_days;

        if ($attachmentThreshold !== null
            && $workingDays > (int) $attachmentThreshold
            && ($attachmentReference === null || trim($attachmentReference) === '')) {
            $conflicts[] = [
                'code' => 'LEAVE_ATTACHMENT_REQUIRED',
                'severity' => 'BLOCKING',
                'message' => 'An attachment is required for a request of this length.',
            ];
        }

        if ($reason !== null && trim($reason) !== '' && mb_strlen(trim($reason)) < 3) {
            $conflicts[] = [
                'code' => 'LEAVE_REASON_TOO_SHORT',
                'severity' => 'BLOCKING',
                'message' => 'A leave request reason must be at least 3 characters.',
            ];
        }

        return $conflicts;
    }

    private function overlap(int $employmentId, string $fromDate, string $toDate, bool $lock = false): ?LeaveRequestProjection
    {
        $query = LeaveRequestProjection::query()
            ->where('employment_id', $employmentId)
            ->whereIn('state', ['SUBMITTED', 'APPROVED', 'CONSUMED'])
            ->where('from_date', '<=', $toDate)
            ->where('to_date', '>=', $fromDate)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function consumedMinutes(LeaveRequestProjection $projection): int
    {
        $consume = LeaveLedgerEntry::query()
            ->where('case_id', $projection->case_id)
            ->where('account', 'CONSUME')
            ->first();

        if ($consume === null) {
            return 0;
        }

        $alreadyReversed = LeaveLedgerEntry::query()
            ->where('case_id', $projection->case_id)
            ->where('account', 'REVERSE')
            ->where('source_type', 'LEAVE_REVOKE')
            ->exists();

        return $alreadyReversed ? 0 : abs((int) $consume->quantity_minutes);
    }

    private function requestedMinutes(int $employmentId, LeaveType $type, int $workingDays, bool $halfDay): int
    {
        if ($type->unit === 'HOUR') {
            return $halfDay ? 30 : max(1, $workingDays * 60);
        }

        $standardDay = $this->accounts->standardDayMinutes($employmentId, $this->clock->now()->toDateString());

        return ($halfDay ? 1 : max(1, $workingDays)) * $standardDay;
    }

    /**
     * Walk the company calendar day by day; weekends and holidays are not
     * silently counted as leave.
     */
    private function workingDays(string $fromDate, string $toDate): int
    {
        $cursor = CarbonImmutable::parse($fromDate, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($toDate, 'UTC')->startOfDay();
        $days = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($this->calendar->isWorkingDay($cursor->format('Y-m-d'))) {
                $days++;
            }

            $cursor = $cursor->addDay();
        }

        return max(1, $days);
    }

    private function policy(LeaveType $type, string $businessDate, bool $forPreview): \App\Modules\Time\Models\LeavePolicyVersion
    {
        if ((string) ($type->status ?? 'ACTIVE') !== 'ACTIVE') {
            throw new ConflictException('The leave type is inactive.', ['reason' => 'LEAVE_TYPE_INACTIVE']);
        }

        $policy = $this->policies->effectiveFor((int) $type->id, $businessDate);

        if ($policy === null) {
            if ($forPreview) {
                throw new ConflictException(
                    'No published leave policy is effective on the requested date.',
                    ['reason' => 'LEAVE_POLICY_UNPUBLISHED'],
                );
            }

            throw new ConflictException(
                'No published leave policy is effective on the requested date.',
                ['reason' => 'LEAVE_POLICY_UNPUBLISHED'],
            );
        }

        return $policy;
    }

    private function type(string $code): LeaveType
    {
        $type = LeaveType::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        if ($type === null) {
            throw new ValidationException(['leave_type' => ['The leave type does not exist in this tenant.']]);
        }

        return $type;
    }
}

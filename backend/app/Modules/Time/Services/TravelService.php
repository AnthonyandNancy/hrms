<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelAttendanceLink;
use App\Modules\Time\Models\TravelExpense;
use App\Modules\Time\Models\TravelPolicy;
use App\Modules\Time\Models\TravelRequestChange;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Travel policy, approval and expense application service.
 *
 * The Workflow Case is the only approval evidence. TravelService writes the
 * trip status only after the Workflow decision has been recorded; an expense
 * additionally requires both the APPROVED status and an APPROVED case so a
 * manual status update can never unlock claims. Expenses are claims only and
 * never create a payroll or payment row.
 */
final class TravelService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly WorkflowCasePort $workflow,
        private readonly EmploymentDirectory $employments,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
        private readonly PayrollFreezeGuard $freezeGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function publishPolicy(array $input, int $actorUserId): array
    {
        $cityTier = strtoupper((string) $input['city_tier']);
        $effectiveFrom = (string) $input['effective_from'];
        $hotelCap = (int) $input['hotel_cap_cents'];
        $transportCap = (int) ($input['transport_cap_cents'] ?? 0);

        if ($hotelCap < 0 || $transportCap < 0) {
            throw new ValidationException(['hotel_cap_cents' => ['Travel caps must be non-negative integer cents.']]);
        }

        return DB::transaction(function () use ($cityTier, $effectiveFrom, $hotelCap, $transportCap, $actorUserId): array {
            $latest = TravelPolicy::query()
                ->where('city_tier', $cityTier)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && $effectiveFrom <= $latest->effective_from->format('Y-m-d')) {
                throw new ConflictException(
                    'A travel policy version must become effective after the latest version.',
                    [
                        'reason' => 'POLICY_EFFECTIVE_DATE_NOT_MONOTONIC',
                        'latest_version' => (int) $latest->version,
                        'latest_effective_from' => $latest->effective_from->format('Y-m-d'),
                    ],
                );
            }

            /** @var TravelPolicy $policy */
            $policy = TravelPolicy::query()->create([
                'city_tier' => $cityTier,
                'hotel_cap_cents' => $hotelCap,
                'transport_cap_cents' => $transportCap,
                'effective_from' => $effectiveFrom,
                'version' => $latest !== null ? (int) $latest->version + 1 : 1,
                'status' => 'PUBLISHED',
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                $actorUserId,
                'time.travel.policy.published',
                'travel_policy',
                (int) $policy->id,
                after: [
                    'city_tier' => $cityTier,
                    'version' => (int) $policy->version,
                    'effective_from' => $effectiveFrom,
                ],
            );

            return $this->policyPayload($policy);
        });
    }

    public function policyProjection(
        ?string $cityTier,
        ?string $asOf,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = TravelPolicy::query();

        if ($cityTier !== null && $cityTier !== '') {
            $query->where('city_tier', strtoupper($cityTier));
        }

        if ($asOf !== null) {
            $query->where('effective_from', '<=', $asOf);
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (TravelPolicy $policy): array => $this->policyPayload($policy))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf ?? $this->clock->now()->toDateString(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'travel_policies',
                'filters' => ['city_tier' => $cityTier],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function openRequest(int $employmentId, array $input, int $actorUserId): array
    {
        $fromDate = (string) $input['from_date'];
        $toDate = (string) $input['to_date'];

        return DB::transaction(function () use ($employmentId, $fromDate, $toDate, $input, $actorUserId): array {
            foreach (PayrollFreezeGuard::periodsForRange($fromDate, $toDate) as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            $overlap = BusinessTripRecord::query()
                ->where('employment_id', $employmentId)
                ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED'])
                ->where('from_date', '<=', $toDate)
                ->where('to_date', '>=', $fromDate)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw new ConflictException('An active travel request overlaps this period.', ['reason' => 'TRAVEL_OVERLAP']);
            }

            $caseId = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'TRAVEL_REQUEST',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'title' => 'Travel request',
                    'employment_id' => $employmentId,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'destination' => (string) $input['destination'],
                    'purpose' => (string) $input['purpose'],
                ],
                businessDate: $fromDate,
            );

            /** @var BusinessTripRecord $record */
            $record = BusinessTripRecord::query()->create([
                'employment_id' => $employmentId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'destination' => (string) $input['destination'],
                'purpose' => (string) $input['purpose'],
                'status' => 'PENDING_APPROVAL',
                'case_id' => $caseId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'time.travel.requested', 'business_trip', (int) $record->id, after: [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'case_id' => $caseId,
            ]);

            return $this->requestPayload($record, $this->workflow->case($caseId));
        });
    }

    /**
     * @param  list<int>  $managedEmploymentIds
     * @return array<string, mixed>
     */
    public function approve(
        int $travelId,
        string $decision,
        string $comment,
        int $actorUserId,
        array $managedEmploymentIds = [],
        bool $legalEntityWide = false,
    ): array {
        $action = strtoupper($decision);

        if (! in_array($action, ['APPROVE', 'REJECT'], true)) {
            throw new ValidationException(['decision' => ['The decision must be APPROVE or REJECT.']]);
        }

        return DB::transaction(function () use ($travelId, $action, $comment, $actorUserId, $managedEmploymentIds, $legalEntityWide): array {
            $trip = BusinessTripRecord::query()
                ->whereKey($travelId)
                ->lockForUpdate()
                ->first();

            if ($trip === null) {
                throw new NotFoundOrForbiddenException;
            }

            $periods = PayrollFreezeGuard::periodsForRange(
                $trip->from_date->format('Y-m-d'),
                $trip->to_date->format('Y-m-d'),
            );

            foreach ($periods as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            $employment = $this->employments->snapshot(
                (int) $trip->employment_id,
                $trip->from_date->format('Y-m-d'),
            );

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! $legalEntityWide && ! in_array((int) $trip->employment_id, $managedEmploymentIds, true)) {
                throw new NotFoundOrForbiddenException;
            }

            if ($trip->case_id === null) {
                throw new ConflictException('The travel request has no workflow approval case.', [
                    'reason' => 'TRAVEL_CASE_MISSING',
                    'travel_id' => $travelId,
                ]);
            }

            if (! in_array((string) $trip->status, ['PENDING_APPROVAL', 'APPROVED'], true)) {
                throw new ConflictException('This travel request cannot be decided in its current state.', [
                    'reason' => 'TRAVEL_NOT_DECIDABLE',
                    'status' => (string) $trip->status,
                ]);
            }

            $pendingChange = TravelRequestChange::query()
                ->where('travel_id', (int) $trip->id)
                ->where('status', TravelRequestChange::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($pendingChange !== null && $action === 'APPROVE') {
                $proposed = (array) $pendingChange->proposed_json;

                foreach (PayrollFreezeGuard::periodsForRange(
                    (string) ($proposed['from_date'] ?? $trip->from_date->format('Y-m-d')),
                    (string) ($proposed['to_date'] ?? $trip->to_date->format('Y-m-d')),
                ) as $period) {
                    $this->freezeGuard->lockAndAssertOpen($period);
                }
            }

            $applicationCaseId = (int) $trip->case_id;
            $changeCaseId = $pendingChange?->case_id !== null ? (int) $pendingChange->case_id : null;

            /*
             * An approved trip cannot be decided again. Its pending change has
             * its own Workflow case, which becomes the decision target. A still
             * pending trip keeps its original case as the primary approval and
             * the change case (when present) is decided in the same command so
             * no orphaned SUBMITTED task survives the trip decision.
             */
            $decideApplication = (string) $trip->status === 'PENDING_APPROVAL';
            $decisionCaseId = $decideApplication ? $applicationCaseId : $changeCaseId;

            if ($decisionCaseId === null) {
                throw new ConflictException('The travel request has no pending approval case.', [
                    'reason' => 'TRAVEL_CASE_ALREADY_DECIDED',
                    'travel_id' => $travelId,
                ]);
            }

            $case = $this->workflow->case($decisionCaseId);

            if ($case === null) {
                throw new ConflictException('The travel approval case was not found.', [
                    'reason' => 'TRAVEL_CASE_MISSING',
                    'case_id' => $decisionCaseId,
                ]);
            }

            if (in_array($case->status, ['APPROVED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'], true)) {
                throw new ConflictException('The travel approval case has already been decided.', [
                    'reason' => 'TRAVEL_CASE_ALREADY_DECIDED',
                    'application_status' => $case->status,
                ]);
            }

            $decideChangeCase = $pendingChange !== null
                && $changeCaseId !== null
                && $changeCaseId !== $applicationCaseId;

            if ($decideChangeCase && $changeCaseId !== $decisionCaseId) {
                $changeCase = $this->workflow->case($changeCaseId);

                if ($changeCase === null) {
                    throw new ConflictException('The travel change case was not found.', [
                        'reason' => 'TRAVEL_CHANGE_CASE_MISSING',
                        'case_id' => $changeCaseId,
                    ]);
                }

                if (in_array($changeCase->status, ['APPROVED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'], true)) {
                    throw new ConflictException('The travel change case has already been decided.', [
                        'reason' => 'TRAVEL_CHANGE_CASE_ALREADY_DECIDED',
                        'application_status' => $changeCase->status,
                    ]);
                }
            }

            $workflowDecision = $action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            $this->workflow->decide($decisionCaseId, $action, $comment);

            $decided = $this->workflow->case($decisionCaseId);

            if ($decided === null || $decided->status !== $workflowDecision) {
                throw new ConflictException('The workflow decision was not recorded.', [
                    'reason' => 'TRAVEL_DECISION_NOT_RECORDED',
                ]);
            }

            if ($decideChangeCase && $changeCaseId !== $decisionCaseId) {
                $this->workflow->decide($changeCaseId, $action, $comment);
                $changeDecision = $this->workflow->case($changeCaseId);

                if ($changeDecision === null || $changeDecision->status !== $workflowDecision) {
                    throw new ConflictException('The travel change workflow decision was not recorded.', [
                        'reason' => 'TRAVEL_CHANGE_DECISION_NOT_RECORDED',
                    ]);
                }
            } elseif ($changeCaseId === $decisionCaseId) {
                $changeDecision = $decided;
            }

            if ($action === 'APPROVE') {
                if ($pendingChange !== null) {
                    $this->applyChangeToTrip($trip, $pendingChange);
                    $pendingChange->forceFill([
                        'status' => TravelRequestChange::STATUS_APPLIED,
                        'applied_at' => $this->clock->now(),
                    ])->save();

                    $this->audit->record(
                        $actorUserId,
                        'time.travel.change_applied',
                        'business_trip',
                        (int) $trip->id,
                        before: $pendingChange->original_json,
                        after: $pendingChange->proposed_json,
                        metadata: ['change_id' => (int) $pendingChange->id, 'case_id' => (int) $trip->case_id],
                    );
                }

                $trip->forceFill(['status' => 'APPROVED'])->save();
                $link = $this->upsertAttendanceLink($trip, $actorUserId);
            } else {
                /*
                 * A rejected change belongs to an already-approved trip: the
                 * original plan stays APPROVED and its attendance link stays
                 * ACTIVE. Only a rejected initial application changes the trip
                 * status to REJECTED.
                 */
                if ($decideApplication) {
                    $trip->forceFill(['status' => 'REJECTED'])->save();
                }

                $link = $this->activeAttendanceLink($trip);

                if ($pendingChange !== null) {
                    $pendingChange->forceFill([
                        'status' => TravelRequestChange::STATUS_REJECTED,
                    ])->save();

                    $this->audit->record(
                        $actorUserId,
                        'time.travel.change_rejected',
                        'business_trip',
                        (int) $trip->id,
                        after: ['change_id' => (int) $pendingChange->id, 'case_id' => (int) $trip->case_id],
                    );
                }
            }

            $this->audit->record(
                $actorUserId,
                'time.travel.decided',
                'business_trip',
                (int) $trip->id,
                before: ['status' => 'PENDING_APPROVAL'],
                after: [
                    'status' => (string) $trip->status,
                    'application_status' => $decided->status,
                    'case_id' => $decisionCaseId,
                    'change_applied' => $pendingChange !== null && $action === 'APPROVE',
                ],
            );

            return $this->requestPayload($trip->refresh(), $decided, $pendingChange, $link);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $employmentIds
     * @return array<string, mixed>
     */
    public function recordExpense(
        array $input,
        int $actorUserId,
        array $employmentIds = [],
        bool $companyWide = false,
    ): array {
        $travelId = (int) $input['travel_id'];
        $amountCents = $input['amount_cents'];
        $category = strtoupper((string) $input['category']);

        if (! is_int($amountCents) || $amountCents < 0) {
            throw new ValidationException(['amount_cents' => ['The amount must be a non-negative integer in minor units.']]);
        }

        if (! in_array($category, TravelExpense::CATEGORIES, true)) {
            throw new ValidationException(['category' => ['The expense category is not supported.']]);
        }

        return DB::transaction(function () use ($travelId, $amountCents, $category, $input, $actorUserId, $employmentIds, $companyWide): array {
            $trip = BusinessTripRecord::query()
                ->whereKey($travelId)
                ->lockForUpdate()
                ->first();

            if ($trip === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $trip->status !== 'APPROVED') {
                throw new ConflictException('Only an approved travel request can accrue expenses.', [
                    'reason' => 'TRIP_NOT_APPROVED',
                    'status' => (string) $trip->status,
                ]);
            }

            if ($trip->case_id === null) {
                throw new ConflictException('Travel expenses require an approved workflow case.', [
                    'reason' => 'TRIP_APPROVAL_EVIDENCE_MISSING',
                    'travel_id' => $travelId,
                ]);
            }

            $case = $this->workflow->case((int) $trip->case_id);

            if ($case === null || ! $case->isApproved()) {
                throw new ConflictException('Travel expenses require an approved workflow case.', [
                    'reason' => 'TRIP_APPROVAL_EVIDENCE_MISSING',
                    'case_id' => (int) $trip->case_id,
                    'application_status' => $case?->status,
                ]);
            }

            $employment = $this->employments->snapshot((int) $trip->employment_id, $trip->from_date->format('Y-m-d'));

            if ($employment === null) {
                throw new NotFoundOrForbiddenException;
            }

            $employmentScoped = $companyWide || in_array((int) $trip->employment_id, $employmentIds, true);

            if (! $employmentScoped) {
                throw new NotFoundOrForbiddenException;
            }

            /** @var TravelExpense $expense */
            $expense = TravelExpense::query()->create([
                'employment_id' => (int) $trip->employment_id,
                'travel_id' => $travelId,
                'category' => $category,
                'amount_cents' => $amountCents,
                'receipt_file_id' => isset($input['receipt_file_id']) ? (int) $input['receipt_file_id'] : null,
                'status' => TravelExpense::STATUS_CLAIMED,
                'note' => isset($input['note']) ? (string) $input['note'] : null,
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'time.travel.expense.claimed', 'travel_expense', (int) $expense->id, after: [
                'travel_id' => $travelId,
                'category' => $category,
                'amount_cents' => $amountCents,
                'payment_status' => 'CLAIM_ONLY',
            ]);

            $this->sensitiveAuditor->record(
                userId: $actorUserId,
                capabilityCode: 'time.travel.expense.manage',
                resourceType: 'travel_expense',
                resourceId: (int) $expense->id,
                fields: ['amount_cents'],
                context: ['profile' => 'P2_SENSITIVE', 'access' => 'WRITE'],
            );

            return $this->expensePayload($expense);
        });
    }

    /**
     * @param  list<int>|null  $employmentIds  null means company-wide
     */
    public function expenseProjection(
        ?array $employmentIds,
        ?int $travelId,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = TravelExpense::query();

        if ($employmentIds === []) {
            $query->whereRaw('1 = 0');
        } elseif ($employmentIds !== null) {
            $query->whereIn('employment_id', $employmentIds);
        }

        if ($travelId !== null) {
            $query->where('travel_id', $travelId);
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (TravelExpense $expense): array => $this->expensePayload($expense))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->clock->now()->toDateString(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'travel_expenses',
                'filters' => ['travel_id' => $travelId],
            ],
        );
    }

    /**
     * @param  array{view?: string|null, status?: string|null, from?: string|null, to?: string|null, employment_id?: int|null, department_id?: int|null, employment_ids?: list<int>|null}  $filters
     * @param  list<int>  $managedEmploymentIds
     * @param  list<int>|null  $employmentScope
     */
    public function requestProjection(
        int $selfEmploymentId,
        array $managedEmploymentIds,
        ?array $employmentScope,
        array $filters,
        int $page,
        int $pageSize,
        string $asOf,
    ): ProjectionResult {
        $query = $this->requestQuery($selfEmploymentId, $managedEmploymentIds, $employmentScope, $filters);
        $total = (clone $query)->count();
        /** @var \Illuminate\Database\Eloquent\Collection<int, BusinessTripRecord> $records */
        $records = $query
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get();
        $items = $records
            ->map(fn (BusinessTripRecord $record): array => $this->requestPayload(
                $record,
                $record->case_id !== null ? $this->workflow->case((int) $record->case_id) : null,
            ))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'business_trip_records',
                'filters' => [
                    'view' => $filters['view'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                    'employment_id' => $filters['employment_id'] ?? null,
                    'department_id' => $filters['department_id'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param  list<int>  $managedEmploymentIds
     * @return list<array<string, mixed>>
     */
    public function listRequests(int $employmentId, array $managedEmploymentIds = [], bool $approvalQueue = false): array
    {
        $query = BusinessTripRecord::query();

        if ($approvalQueue) {
            $query->whereIn('employment_id', $managedEmploymentIds)
                ->where('status', 'PENDING_APPROVAL');
        } else {
            $query->where('employment_id', $employmentId);
        }

        return $query
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BusinessTripRecord $record): array => $this->requestPayload(
                $record,
                $record->case_id !== null ? $this->workflow->case((int) $record->case_id) : null,
            ))
            ->all();
    }

    /**
     * @param  array{view?: string|null, status?: string|null, from?: string|null, to?: string|null, employment_id?: int|null, department_id?: int|null, employment_ids?: list<int>|null}  $filters
     * @param  list<int>  $managedEmploymentIds
     * @param  list<int>|null  $employmentScope
     * @return Builder<BusinessTripRecord>
     */
    private function requestQuery(
        int $selfEmploymentId,
        array $managedEmploymentIds,
        ?array $employmentScope,
        array $filters,
    ): Builder {
        $query = BusinessTripRecord::query();
        $view = strtolower((string) ($filters['view'] ?? 'my'));
        $companyWide = $employmentScope === null;

        if ($view === 'my') {
            $query->where('employment_id', $selfEmploymentId);
        } elseif ($view === 'my_approvals') {
            if (! $companyWide) {
                $query->whereIn('employment_id', $managedEmploymentIds);
            }
            $query->where('status', 'PENDING_APPROVAL');
        } elseif ($view === 'team') {
            if (! $companyWide) {
                $query->whereIn('employment_id', $managedEmploymentIds);
            }
        }

        if ($employmentScope !== null) {
            $query->whereIn('employment_id', $employmentScope);
        }

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', (int) $filters['employment_id']);
        }

        if (($filters['employment_ids'] ?? null) !== null) {
            $query->whereIn('employment_id', (array) $filters['employment_ids']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $query->where('to_date', '>=', (string) $filters['from']);
        }

        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $query->where('from_date', '<=', (string) $filters['to']);
        }

        if (($filters['department_id'] ?? null) !== null && ($filters['employment_ids'] ?? null) === null) {
            // Department membership lives in People. Time must not read the
            // People assignment table directly; the controller expands the
            // department through the Shared authorization directory and passes
            // the resulting employment IDs in `employment_ids`.
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $employmentId, int $id, string $reason, int $actorUserId): array
    {
        return DB::transaction(function () use ($employmentId, $id, $reason, $actorUserId): array {
            $record = BusinessTripRecord::query()
                ->where('employment_id', $employmentId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (in_array((string) $record->status, ['CANCELLED', 'COMPLETED'], true)) {
                throw new ConflictException('This travel request cannot be cancelled in its current state.', [
                    'reason' => 'TRAVEL_NOT_CANCELLABLE',
                    'status' => (string) $record->status,
                ]);
            }

            foreach (PayrollFreezeGuard::periodsForRange(
                $record->from_date->format('Y-m-d'),
                $record->to_date->format('Y-m-d'),
            ) as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            $this->assertExpensesCancellable((int) $record->id);

            $record->forceFill([
                'status' => 'CANCELLED',
                'cancel_reason' => $reason,
                'cancelled_at' => $this->clock->now(),
            ])->save();

            $pendingChanges = TravelRequestChange::query()
                ->where('travel_id', (int) $record->id)
                ->where('status', TravelRequestChange::STATUS_PENDING)
                ->get();

            foreach ($pendingChanges as $pendingChange) {
                $pendingChange->forceFill(['status' => TravelRequestChange::STATUS_CANCELLED])->save();
            }

            $this->retireAttendanceLink((int) $record->id, $reason, $actorUserId);

            $this->audit->record($actorUserId, 'time.travel.cancelled', 'business_trip', (int) $record->id, after: [
                'reason' => $reason,
                'cancelled_change_ids' => $pendingChanges->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            ]);

            return $this->requestPayload(
                $record->refresh(),
                $record->case_id !== null ? $this->workflow->case((int) $record->case_id) : null,
            );
        });
    }

    /**
     * Request a business-trip change. The original trip is never mutated until
     * the linked Workflow case is approved and approve() applies proposed_json.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function requestChange(int $employmentId, int $travelId, array $input, int $actorUserId): array
    {
        $fromDate = (string) $input['from_date'];
        $toDate = (string) $input['to_date'];
        $reason = trim((string) $input['reason']);

        if ($reason === '') {
            throw new ValidationException(['reason' => ['A change reason is required.']]);
        }

        return DB::transaction(function () use ($employmentId, $travelId, $input, $actorUserId, $fromDate, $toDate, $reason): array {
            foreach (PayrollFreezeGuard::periodsForRange($fromDate, $toDate) as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            $trip = BusinessTripRecord::query()
                ->where('employment_id', $employmentId)
                ->whereKey($travelId)
                ->lockForUpdate()
                ->first();

            if ($trip === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $trip->status, ['PENDING_APPROVAL', 'APPROVED'], true)) {
                throw new ConflictException('This travel request cannot be changed in its current state.', [
                    'reason' => 'TRAVEL_NOT_CHANGEABLE',
                    'status' => (string) $trip->status,
                ]);
            }

            $pending = TravelRequestChange::query()
                ->where('travel_id', $travelId)
                ->where('status', TravelRequestChange::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw new ConflictException('A travel change is already pending approval.', [
                    'reason' => 'TRAVEL_CHANGE_ALREADY_PENDING',
                    'travel_id' => $travelId,
                ]);
            }

            $overlap = BusinessTripRecord::query()
                ->where('employment_id', $employmentId)
                ->whereKeyNot($travelId)
                ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED'])
                ->where('from_date', '<=', $toDate)
                ->where('to_date', '>=', $fromDate)
                ->exists();

            if ($overlap) {
                throw new ConflictException('An active travel request overlaps the proposed period.', [
                    'reason' => 'TRAVEL_OVERLAP',
                ]);
            }

            $original = $this->tripSnapshot($trip);
            $proposed = [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'destination' => (string) $input['destination'],
                'purpose' => (string) $input['purpose'],
            ];

            $caseId = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'TRAVEL_REQUEST',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'title' => 'Travel request change',
                    'employment_id' => $employmentId,
                    'travel_id' => $travelId,
                    'change' => true,
                    'original' => $original,
                    'proposed' => $proposed,
                    'reason' => $reason,
                ],
                businessDate: $fromDate,
            );

            /** @var TravelRequestChange $change */
            $change = TravelRequestChange::query()->create([
                'travel_id' => $travelId,
                'employment_id' => $employmentId,
                'case_id' => $caseId,
                'original_json' => $original,
                'proposed_json' => $proposed,
                'reason' => $reason,
                'status' => TravelRequestChange::STATUS_PENDING,
                'recorded_at' => $this->clock->now(),
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                $actorUserId,
                'time.travel.change_requested',
                'business_trip',
                $travelId,
                before: $original,
                after: $proposed,
                metadata: [
                    'change_id' => (int) $change->id,
                    'case_id' => $caseId,
                    'reason' => $reason,
                ],
            );

            return [
                'change_id' => (int) $change->id,
                'travel_id' => $travelId,
                'case_id' => $caseId,
                'change_status' => TravelRequestChange::STATUS_PENDING,
                'original' => $original,
                'proposed' => $proposed,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Apply the pending change whose Workflow case was just approved.
     */
    public function applyPendingChange(BusinessTripRecord $trip, ?TravelRequestChange $change = null): ?TravelRequestChange
    {
        $change ??= TravelRequestChange::query()
            ->where('travel_id', (int) $trip->id)
            ->where('case_id', (int) $trip->case_id)
            ->where('status', TravelRequestChange::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if ($change === null) {
            return null;
        }

        $proposed = (array) $change->proposed_json;
        $periods = [
            ...PayrollFreezeGuard::periodsForRange(
                $trip->from_date->format('Y-m-d'),
                $trip->to_date->format('Y-m-d'),
            ),
            ...PayrollFreezeGuard::periodsForRange(
                (string) ($proposed['from_date'] ?? $trip->from_date->format('Y-m-d')),
                (string) ($proposed['to_date'] ?? $trip->to_date->format('Y-m-d')),
            ),
        ];

        return DB::transaction(function () use ($trip, $change, $periods): TravelRequestChange {
            foreach (array_values(array_unique($periods)) as $period) {
                $this->freezeGuard->lockAndAssertOpen($period);
            }

            $this->applyChangeToTrip($trip, $change);
            $change->forceFill([
                'status' => TravelRequestChange::STATUS_APPLIED,
                'applied_at' => $this->clock->now(),
            ])->save();

            return $change->refresh();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeAttendanceLink(BusinessTripRecord $trip): ?array
    {
        $link = TravelAttendanceLink::query()
            ->where('travel_id', (int) $trip->id)
            ->first();

        return $link === null ? null : $this->attendanceLinkPayload($link);
    }

    private function applyChangeToTrip(BusinessTripRecord $trip, TravelRequestChange $change): void
    {
        $proposed = $change->proposed_json;

        $trip->forceFill([
            'from_date' => (string) $proposed['from_date'],
            'to_date' => (string) $proposed['to_date'],
            'destination' => (string) $proposed['destination'],
            'purpose' => (string) $proposed['purpose'],
        ])->save();
    }

    /**
     * Create or refresh the calendar-only link for an APPROVED trip.
     *
     * @return array<string, mixed>
     */
    private function upsertAttendanceLink(BusinessTripRecord $trip, int $actorUserId): array
    {
        if ($trip->case_id === null) {
            throw new ConflictException('Travel attendance links require an approved workflow case.', [
                'reason' => 'TRIP_APPROVAL_EVIDENCE_MISSING',
                'travel_id' => (int) $trip->id,
            ]);
        }

        /** @var TravelAttendanceLink $link */
        $link = TravelAttendanceLink::query()
            ->where('travel_id', (int) $trip->id)
            ->first();

        if ($link === null) {
            /** @var TravelAttendanceLink $link */
            $link = TravelAttendanceLink::query()->create([
                'travel_id' => (int) $trip->id,
                'employment_id' => (int) $trip->employment_id,
                'from_date' => $trip->from_date->format('Y-m-d'),
                'to_date' => $trip->to_date->format('Y-m-d'),
                'source_case_id' => (int) $trip->case_id,
                'status' => TravelAttendanceLink::STATUS_ACTIVE,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);
        } else {
            $link->forceFill([
                'employment_id' => (int) $trip->employment_id,
                'from_date' => $trip->from_date->format('Y-m-d'),
                'to_date' => $trip->to_date->format('Y-m-d'),
                'source_case_id' => (int) $trip->case_id,
                'status' => TravelAttendanceLink::STATUS_ACTIVE,
                'retired_at' => null,
                'retire_reason' => null,
                'updated_at' => $this->clock->now(),
            ])->save();
        }

        $this->audit->record(
            $actorUserId,
            'time.travel.attendance_linked',
            'business_trip',
            (int) $trip->id,
            after: $this->attendanceLinkPayload($link),
        );

        return $this->attendanceLinkPayload($link);
    }

    private function retireAttendanceLink(int $travelId, string $reason, int $actorUserId): void
    {
        $link = TravelAttendanceLink::query()
            ->where('travel_id', $travelId)
            ->where('status', TravelAttendanceLink::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($link === null) {
            return;
        }

        $link->forceFill([
            'status' => TravelAttendanceLink::STATUS_RETIRED,
            'retired_at' => $this->clock->now(),
            'retire_reason' => $reason,
        ])->save();

        $this->audit->record(
            $actorUserId,
            'time.travel.attendance_retired',
            'business_trip',
            $travelId,
            after: $this->attendanceLinkPayload($link),
        );
    }

    /**
     * Expenses are CLAIM_ONLY in this module. A settled payment does not exist
     * yet, so only an unexpected persisted status is treated as blocking.
     */
    private function assertExpensesCancellable(int $travelId): void
    {
        $settled = TravelExpense::query()
            ->where('travel_id', $travelId)
            ->whereNotIn('status', [TravelExpense::STATUS_CLAIMED])
            ->exists();

        if ($settled) {
            throw new ConflictException('A travel request with settled expenses cannot be cancelled.', [
                'reason' => 'TRAVEL_EXPENSE_SETTLED',
                'travel_id' => $travelId,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tripSnapshot(BusinessTripRecord $trip): array
    {
        return [
            'from_date' => $trip->from_date->format('Y-m-d'),
            'to_date' => $trip->to_date->format('Y-m-d'),
            'destination' => (string) $trip->destination,
            'purpose' => (string) $trip->purpose,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceLinkPayload(TravelAttendanceLink $link): array
    {
        return [
            'id' => (int) $link->id,
            'travel_id' => (int) $link->travel_id,
            'employment_id' => (int) $link->employment_id,
            'from_date' => $link->from_date->format('Y-m-d'),
            'to_date' => $link->to_date->format('Y-m-d'),
            'source_case_id' => (int) $link->source_case_id,
            'status' => (string) $link->status,
            'retired_at' => $link->retired_at?->toIso8601ZuluString(),
            'retire_reason' => $link->retire_reason,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $attendanceLink
     * @return array<string, mixed>
     */
    public function requestPayload(
        BusinessTripRecord $record,
        ?WorkflowCaseSnapshot $case,
        ?TravelRequestChange $change = null,
        ?array $attendanceLink = null,
    ): array {
        return [
            'travel_id' => (int) $record->id,
            'employment_id' => (int) $record->employment_id,
            'from_date' => $record->from_date->format('Y-m-d'),
            'to_date' => $record->to_date->format('Y-m-d'),
            'destination' => $record->destination,
            'purpose' => $record->purpose,
            'status' => (string) $record->status,
            'case_id' => $record->case_id !== null ? (int) $record->case_id : null,
            'case_no' => $case?->caseNo,
            'application_status' => $case?->status,
            'change' => $change === null ? null : [
                'change_id' => (int) $change->id,
                'status' => (string) $change->status,
                'case_id' => $change->case_id !== null ? (int) $change->case_id : null,
                'reason' => (string) $change->reason,
            ],
            'attendance_link' => $attendanceLink,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policyPayload(TravelPolicy $policy): array
    {
        return [
            'id' => (int) $policy->id,
            'city_tier' => (string) $policy->city_tier,
            'hotel_cap_cents' => (int) $policy->hotel_cap_cents,
            'transport_cap_cents' => (int) $policy->transport_cap_cents,
            'effective_from' => $policy->effective_from->format('Y-m-d'),
            'version' => (int) $policy->version,
            'status' => (string) $policy->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expensePayload(TravelExpense $expense): array
    {
        return [
            'id' => (int) $expense->id,
            'travel_id' => (int) $expense->travel_id,
            'employment_id' => (int) $expense->employment_id,
            'category' => (string) $expense->category,
            'amount_cents' => (int) $expense->amount_cents,
            'currency' => 'CNY',
            'receipt_file_id' => $expense->receipt_file_id !== null ? (int) $expense->receipt_file_id : null,
            'status' => (string) $expense->status,
            'payment_status' => 'CLAIM_ONLY',
            'note' => $expense->note,
            'claimed_at' => $expense->created_at?->toIso8601ZuluString(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelExpense;
use App\Modules\Time\Models\TravelPolicy;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
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

        $overlap = BusinessTripRecord::query()
            ->where('employment_id', $employmentId)
            ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED'])
            ->where('from_date', '<=', $toDate)
            ->where('to_date', '>=', $fromDate)
            ->exists();

        if ($overlap) {
            throw new ConflictException('An active travel request overlaps this period.', ['reason' => 'TRAVEL_OVERLAP']);
        }

        return DB::transaction(function () use ($employmentId, $fromDate, $toDate, $input, $actorUserId): array {
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

            if (! in_array((string) $trip->status, ['PENDING_APPROVAL', 'APPROVED', 'REJECTED'], true)) {
                throw new ConflictException('This travel request cannot be decided in its current state.', [
                    'reason' => 'TRAVEL_NOT_DECIDABLE',
                    'status' => (string) $trip->status,
                ]);
            }

            $case = $this->workflow->case((int) $trip->case_id);

            if ($case === null) {
                throw new ConflictException('The travel approval case was not found.', [
                    'reason' => 'TRAVEL_CASE_MISSING',
                    'case_id' => (int) $trip->case_id,
                ]);
            }

            if (in_array($case->status, ['APPROVED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'], true)) {
                throw new ConflictException('The travel approval case has already been decided.', [
                    'reason' => 'TRAVEL_CASE_ALREADY_DECIDED',
                    'application_status' => $case->status,
                ]);
            }

            $this->workflow->decide(
                (int) $trip->case_id,
                $action === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                $comment,
            );

            $decided = $this->workflow->case((int) $trip->case_id);

            if ($decided === null || ! in_array($decided->status, ['APPROVED', 'REJECTED'], true)) {
                throw new ConflictException('The workflow decision was not recorded.', [
                    'reason' => 'TRAVEL_DECISION_NOT_RECORDED',
                ]);
            }

            $trip->forceFill(['status' => $action === 'APPROVE' ? 'APPROVED' : 'REJECTED'])->save();

            $this->audit->record(
                $actorUserId,
                'time.travel.decided',
                'business_trip',
                (int) $trip->id,
                before: ['status' => 'PENDING_APPROVAL'],
                after: [
                    'status' => (string) $trip->status,
                    'application_status' => $decided->status,
                    'case_id' => (int) $trip->case_id,
                ],
            );

            return $this->requestPayload($trip->refresh(), $decided);
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
     * @return array<string, mixed>
     */
    public function cancel(int $employmentId, int $id, string $reason, int $actorUserId): array
    {
        $record = BusinessTripRecord::query()
            ->where('employment_id', $employmentId)
            ->whereKey($id)
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

        $record->forceFill([
            'status' => 'CANCELLED',
            'cancel_reason' => $reason,
            'cancelled_at' => $this->clock->now(),
        ])->save();

        $this->audit->record($actorUserId, 'time.travel.cancelled', 'business_trip', (int) $record->id, after: [
            'reason' => $reason,
        ]);

        return $this->requestPayload(
            $record,
            $record->case_id !== null ? $this->workflow->case((int) $record->case_id) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function requestPayload(BusinessTripRecord $record, ?WorkflowCaseSnapshot $case): array
    {
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

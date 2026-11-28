<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeRequest;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\TimeoffLedgerEntry;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Money\Decimal;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Overtime application, policy and confirmation facts.
 *
 *  - apply() stores the employee declaration in `declared_minutes` only;
 *  - confirm() requires an APPROVED request, resolves the company policy in
 *    force on the business date, stores a snapshot and materialises exactly one
 *    `overtime_records` row;
 *  - policy rows are append-only company facts. No code-owned default is
 *    returned when the company has not configured a version.
 *
 * The record is the only compensation key. `overtime_settlements` has a unique
 * `overtime_record_id`, so PAY and TIMEOFF can never both happen even under a
 * race. Time-off balances are always derived from TIMEOFF settlements; no
 * balance row is written or cached here.
 */
final class OvertimeRequestService
{
    public const POLICY_SOURCE = 'COMPANY_VERSION';

    public const MULTIPLIER_PATTERN = '/^\d{1,6}\.\d{6,18}$/D';

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly EmploymentDirectory $employments,
        private readonly Container $container,
        private readonly PayrollFreezeGuard $freezeGuard,
        private readonly TimeoffLedgerService $timeoffLedger,
        private readonly WorkflowCasePort $workflow,
    ) {}

    public function apply(
        int $employmentId,
        string $businessDate,
        int $declaredMinutes,
        string $reason,
        int $actorUserId,
    ): OvertimeRequest {
        if ($declaredMinutes < 1) {
            throw new ConflictException('Declared overtime minutes must be positive.', [
                'reason' => 'OVERTIME_REQUEST_INVALID_MINUTES',
            ]);
        }

        BusinessDate::parse($businessDate);

        return DB::transaction(function () use ($employmentId, $businessDate, $declaredMinutes, $reason, $actorUserId): OvertimeRequest {
            $this->freezeGuard->lockAndAssertOpen(substr($businessDate, 0, 7));

            $duplicate = OvertimeRequest::query()
                ->where('employment_id', $employmentId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new ConflictException('An overtime request already exists for this employee and date.', [
                    'reason' => 'OVERTIME_REQUEST_DUPLICATE',
                    'overtime_request_id' => (int) $duplicate->id,
                ]);
            }

            $caseId = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'OVERTIME_REQUEST',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'title' => 'Overtime request',
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'declared_minutes' => $declaredMinutes,
                    'reason' => $reason,
                ],
                businessDate: $businessDate,
            );

            try {
                /** @var OvertimeRequest $request */
                $request = OvertimeRequest::query()->create([
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'declared_minutes' => $declaredMinutes,
                    'confirmed_minutes' => null,
                    'status' => OvertimeRequest::STATUS_SUBMITTED,
                    'reason' => $reason,
                    'case_id' => $caseId,
                    'overtime_record_id' => null,
                    'policy_version_id' => null,
                    'policy_snapshot_json' => null,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException('An overtime request already exists for this employee and date.', [
                    'reason' => 'OVERTIME_REQUEST_DUPLICATE',
                ]);
            }

            $this->audit->record($actorUserId, 'time.overtime.requested', 'overtime_request', (int) $request->id, after: [
                'employment_id' => $employmentId,
                'business_date' => $businessDate,
                'declared_minutes' => $declaredMinutes,
                'case_id' => $caseId,
            ]);

            return $request;
        });
    }

    /**
     * Move APPROVED -> CONFIRMED exactly once and materialise the overtime
     * record used by the settlement command. The policy snapshot is written in
     * the same transaction as the state transition and record.
     */
    public function approve(int $overtimeRequestId, string $decision, string $comment, int $actorUserId): OvertimeRequest
    {
        $action = strtoupper($decision);
        if (! in_array($action, ['APPROVE', 'REJECT'], true)) {
            throw new ValidationException(['decision' => ['The decision must be APPROVE or REJECT.']]);
        }

        return DB::transaction(function () use ($overtimeRequestId, $action, $comment, $actorUserId): OvertimeRequest {
            $request = OvertimeRequest::query()->whereKey($overtimeRequestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }
            if ((string) $request->status !== OvertimeRequest::STATUS_SUBMITTED || $request->case_id === null) {
                throw new ConflictException('Only a submitted overtime request can be decided.', [
                    'reason' => 'OVERTIME_REQUEST_NOT_SUBMITTED',
                    'status' => (string) $request->status,
                ]);
            }

            $case = $this->workflow->case((int) $request->case_id);
            if ($case === null) {
                throw new ConflictException('The overtime workflow case was not found.', [
                    'reason' => 'OVERTIME_CASE_MISSING',
                    'case_id' => (int) $request->case_id,
                ]);
            }
            if (in_array($case->status, ['APPROVED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'], true)) {
                throw new ConflictException('The overtime workflow case has already been decided.', [
                    'reason' => 'OVERTIME_CASE_ALREADY_DECIDED',
                    'status' => $case->status,
                ]);
            }

            $this->workflow->decide((int) $request->case_id, $action, $comment);
            $decided = $this->workflow->case((int) $request->case_id);
            $expected = $action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            if ($decided === null || $decided->status !== $expected) {
                throw new ConflictException('The overtime workflow decision did not reach the expected state.', [
                    'reason' => 'OVERTIME_CASE_DECISION_FAILED',
                    'case_id' => (int) $request->case_id,
                ]);
            }

            $request->forceFill([
                'status' => $action === 'APPROVE' ? OvertimeRequest::STATUS_APPROVED : OvertimeRequest::STATUS_REJECTED,
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.overtime.decided',
                'overtime_request',
                (int) $request->id,
                after: ['decision' => $action, 'case_id' => (int) $request->case_id, 'comment' => $comment],
            );

            return $request->refresh();
        });
    }

    public function confirm(
        int $overtimeRequestId,
        int $confirmedMinutes,
        int $actorUserId,
    ): OvertimeRequest {
        if ($confirmedMinutes < 1) {
            throw new ConflictException('Confirmed overtime minutes must be positive.', [
                'reason' => 'OVERTIME_REQUEST_INVALID_MINUTES',
            ]);
        }

        return DB::transaction(function () use ($overtimeRequestId, $confirmedMinutes, $actorUserId): OvertimeRequest {
            $request = OvertimeRequest::query()
                ->whereKey($overtimeRequestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $request->status === OvertimeRequest::STATUS_CONFIRMED) {
                throw new ConflictException('This overtime request has already been confirmed.', [
                    'reason' => 'ALREADY_CONFIRMED',
                    'confirmed_minutes' => (int) $request->confirmed_minutes,
                    'overtime_record_id' => $request->overtime_record_id !== null ? (int) $request->overtime_record_id : null,
                ]);
            }

            if ((string) $request->status !== OvertimeRequest::STATUS_APPROVED) {
                throw new ConflictException('Only an approved overtime request can be confirmed.', [
                    'reason' => 'OVERTIME_REQUEST_NOT_APPROVED',
                    'status' => (string) $request->status,
                ]);
            }

            $businessDate = $request->business_date->format('Y-m-d');
            $this->freezeGuard->lockAndAssertOpen(substr($businessDate, 0, 7));
            $policy = $this->effectiveFor($businessDate);

            if ($policy === null) {
                throw new ConflictException(
                    'No company overtime policy is effective on this business date.',
                    [
                        'reason' => 'OVERTIME_POLICY_UNCONFIGURED',
                        'business_date' => $businessDate,
                    ],
                );
            }

            $snapshot = $this->snapshot($policy);

            /** @var OvertimeRecord $record */
            $record = OvertimeRecord::query()->create([
                'employment_id' => (int) $request->employment_id,
                'business_date' => $businessDate,
                'minutes' => $confirmedMinutes,
                'status' => 'APPROVED',
                'case_id' => $request->case_id !== null ? (int) $request->case_id : null,
                'created_at' => $this->clock->now(),
            ]);

            $request->forceFill([
                'confirmed_minutes' => $confirmedMinutes,
                'status' => OvertimeRequest::STATUS_CONFIRMED,
                'overtime_record_id' => (int) $record->id,
                'policy_version_id' => (int) $policy->id,
                'policy_snapshot_json' => $snapshot,
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.overtime.confirmed',
                'overtime_request',
                (int) $request->id,
                before: [
                    'status' => OvertimeRequest::STATUS_APPROVED,
                    'confirmed_minutes' => null,
                    'policy_version_id' => null,
                    'policy_snapshot' => null,
                ],
                after: [
                    'status' => OvertimeRequest::STATUS_CONFIRMED,
                    'declared_minutes' => (int) $request->declared_minutes,
                    'confirmed_minutes' => $confirmedMinutes,
                    'overtime_record_id' => (int) $record->id,
                    'policy_version_id' => (int) $policy->id,
                    'policy_snapshot' => $snapshot,
                ],
            );

            return $request->refresh();
        });
    }

    public function policyVersion(int $policyId): ?OvertimePolicyVersion
    {
        return OvertimePolicyVersion::query()->whereKey($policyId)->first();
    }

    /**
     * Effective company policy. No fallback to config or a code-owned default.
     */
    public function effectiveFor(string $businessDate): ?OvertimePolicyVersion
    {
        $date = BusinessDate::from($businessDate)->value();

        return OvertimePolicyVersion::query()
            ->where('status', OvertimePolicyVersion::STATUS_PUBLISHED)
            ->whereNull('retired_at')
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>=', $date)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, as_of: string, source: string, current: array<string, mixed>|null}
     */
    public function policyList(string $asOf): array
    {
        $items = OvertimePolicyVersion::query()
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OvertimePolicyVersion $policy): array => $this->policyPayload($policy))
            ->all();

        $current = $this->effectiveFor($asOf);

        return [
            'items' => $items,
            'total' => count($items),
            'as_of' => $asOf,
            'source' => self::POLICY_SOURCE,
            'current' => $current !== null ? $this->policyPayload($current) : null,
        ];
    }

    /**
     * Append a company policy version. The caller cannot supply `version`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function appendPolicy(array $input, int $actorUserId): array
    {
        $effectiveFrom = BusinessDate::from((string) $input['effective_from'])->value();
        $effectiveTo = BusinessDate::from((string) $input['effective_to'])->value();

        if ($effectiveTo < $effectiveFrom) {
            throw new ValidationException(
                ['effective_to' => ['The policy end date must not precede the start date.']],
                'The given data was invalid.',
                ['reason' => 'OVERTIME_POLICY_RANGE_INVALID'],
            );
        }

        $timeUnitMinutes = (int) ($input['time_unit_minutes'] ?? 30);

        if (! in_array($timeUnitMinutes, OvertimePolicyVersion::TIME_UNITS, true)) {
            throw new ValidationException([
                'time_unit_minutes' => ['The time unit must be one of 1, 5, 10, 15, 30 or 60 minutes.'],
            ]);
        }

        $multiplier = $this->multiplier($input['multiplier'] ?? '1.500000', 'multiplier');
        $statutoryMultiplier = $this->multiplier(
            $input['statutory_holiday_multiplier'] ?? '3.000000',
            'statutory_holiday_multiplier',
        );
        $allowPay = (bool) ($input['allow_pay'] ?? true);
        $allowTimeoff = (bool) ($input['allow_timeoff'] ?? true);

        if (! $allowPay && ! $allowTimeoff) {
            throw new ValidationException([
                'allow_pay' => ['At least one compensation method must be enabled.'],
            ]);
        }

        $allocation = strtoupper((string) ($input['allocation'] ?? 'EMPLOYEE_CHOICE'));

        if (! in_array($allocation, OvertimePolicyVersion::ALLOCATIONS, true)) {
            throw new ValidationException(['allocation' => ['Unsupported overtime allocation mode.']]);
        }

        $rounding = strtoupper((string) ($input['rounding'] ?? 'HALF_UP'));

        if (! in_array($rounding, ['HALF_UP', 'HALF_EVEN', 'DOWN', 'UP'], true)) {
            throw new ValidationException(['rounding' => ['Unsupported overtime rounding mode.']]);
        }

        $legalBasis = isset($input['legal_basis']) && trim((string) $input['legal_basis']) !== ''
            ? trim((string) $input['legal_basis'])
            : null;

        if ($legalBasis !== null && mb_strlen($legalBasis) > 255) {
            throw new ValidationException(['legal_basis' => ['The legal basis may not exceed 255 characters.']]);
        }

        return DB::transaction(function () use (
            $effectiveFrom,
            $effectiveTo,
            $timeUnitMinutes,
            $multiplier,
            $statutoryMultiplier,
            $allowPay,
            $allowTimeoff,
            $allocation,
            $rounding,
            $legalBasis,
            $actorUserId,
        ): array {
            // Lock every existing policy row so version allocation and overlap
            // validation cannot observe different snapshots in one transaction.
            $existing = OvertimePolicyVersion::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $overlap = $existing->first(static function (OvertimePolicyVersion $policy) use ($effectiveFrom, $effectiveTo): bool {
                if ($policy->retired_at !== null || (string) $policy->status !== OvertimePolicyVersion::STATUS_PUBLISHED) {
                    return false;
                }

                return $policy->effective_from->format('Y-m-d') <= $effectiveTo
                    && $policy->effective_to->format('Y-m-d') >= $effectiveFrom;
            });

            if ($overlap !== null) {
                throw new ConflictException(
                    'A live overtime policy version already overlaps the requested effective window.',
                    [
                        'reason' => 'OVERTIME_POLICY_RANGE_OVERLAP',
                        'overlapping_version' => (int) $overlap->version,
                    ],
                );
            }

            $version = ((int) $existing->max('version')) + 1;

            try {
                /** @var OvertimePolicyVersion $policy */
                $policy = OvertimePolicyVersion::query()->create([
                    'version' => $version,
                    'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'time_unit_minutes' => $timeUnitMinutes,
                    'multiplier' => $multiplier,
                    'statutory_holiday_multiplier' => $statutoryMultiplier,
                    'allow_pay' => $allowPay,
                    'allow_timeoff' => $allowTimeoff,
                    'allocation' => $allocation,
                    'rounding' => $rounding,
                    'legal_basis' => $legalBasis,
                    'published_at' => $this->clock->now(),
                    'published_by' => $actorUserId,
                    'created_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException(
                    'Another overtime policy version was appended concurrently; retry the command.',
                    ['reason' => 'OVERTIME_POLICY_VERSION_CONFLICT'],
                );
            }

            $after = $this->policyPayload($policy);

            $this->audit->record(
                $actorUserId,
                'time.overtime.policy.published',
                'overtime_policy_version',
                (int) $policy->id,
                after: $after,
            );

            return $after;
        });
    }

    /**
     * Retire a future, not-yet-effective policy version. Confirmed requests keep
     * their immutable snapshot and are never rewritten.
     *
     * @return array<string, mixed>
     */
    public function retirePolicy(int $policyId, string $reason, int $actorUserId): array
    {
        $today = BusinessDate::today(
            (string) config('hrms.default_timezone', 'Asia/Shanghai'),
            $this->clock,
        )->value();

        return DB::transaction(function () use ($policyId, $reason, $actorUserId, $today): array {
            /** @var OvertimePolicyVersion|null $policy */
            $policy = OvertimePolicyVersion::query()
                ->whereKey($policyId)
                ->lockForUpdate()
                ->first();

            if ($policy === null) {
                throw new NotFoundOrForbiddenException;
            }

            $before = $this->policyPayload($policy);

            if ($policy->retired_at !== null || (string) $policy->status === OvertimePolicyVersion::STATUS_RETIRED) {
                return $before;
            }

            if ($policy->effective_from->format('Y-m-d') <= $today) {
                throw new ConflictException(
                    'An overtime policy that has already taken effect cannot be retired; append a later version instead.',
                    [
                        'reason' => 'OVERTIME_POLICY_ALREADY_EFFECTIVE',
                        'effective_from' => $policy->effective_from->format('Y-m-d'),
                        'today' => $today,
                    ],
                );
            }

            $policy->forceFill([
                'status' => OvertimePolicyVersion::STATUS_RETIRED,
                'retired_at' => $this->clock->now(),
                'retired_by' => $actorUserId,
                'retire_reason' => trim($reason) !== '' ? trim($reason) : 'Retired by administrator',
            ])->save();

            $after = $this->policyPayload($policy->refresh());

            $this->audit->record(
                $actorUserId,
                'time.overtime.policy.retired',
                'overtime_policy_version',
                (int) $policy->id,
                before: $before,
                after: $after,
            );

            return $after;
        });
    }

    /**
     * Immutable confirmation snapshot. Multipliers remain exact decimal strings.
     *
     * @return array<string, mixed>
     */
    public function snapshot(OvertimePolicyVersion $policy): array
    {
        return [
            'policy_version_id' => (int) $policy->id,
            'version' => (int) $policy->version,
            'effective_from' => $policy->effective_from->format('Y-m-d'),
            'effective_to' => $policy->effective_to->format('Y-m-d'),
            'time_unit_minutes' => (int) $policy->time_unit_minutes,
            'multiplier' => (string) $policy->multiplier,
            'statutory_holiday_multiplier' => (string) $policy->statutory_holiday_multiplier,
            'allow_pay' => (bool) $policy->allow_pay,
            'allow_timeoff' => (bool) $policy->allow_timeoff,
            'allocation' => (string) $policy->allocation,
            'rounding' => (string) $policy->rounding,
            'legal_basis' => $policy->legal_basis,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policyPayload(OvertimePolicyVersion $policy): array
    {
        return [
            'id' => (int) $policy->id,
            'version' => (int) $policy->version,
            'status' => (string) $policy->status,
            'effective_from' => $policy->effective_from->format('Y-m-d'),
            'effective_to' => $policy->effective_to->format('Y-m-d'),
            'time_unit_minutes' => (int) $policy->time_unit_minutes,
            'multiplier' => (string) $policy->multiplier,
            'statutory_holiday_multiplier' => (string) $policy->statutory_holiday_multiplier,
            'allow_pay' => (bool) $policy->allow_pay,
            'allow_timeoff' => (bool) $policy->allow_timeoff,
            'allocation' => (string) $policy->allocation,
            'rounding' => (string) $policy->rounding,
            'legal_basis' => $policy->legal_basis,
            'source' => self::POLICY_SOURCE,
            'published_at' => $policy->published_at->toIso8601ZuluString(),
            'published_by' => (int) $policy->published_by,
            'retired_at' => $policy->retired_at?->toIso8601ZuluString(),
            'retired_by' => $policy->retired_by !== null ? (int) $policy->retired_by : null,
            'retire_reason' => $policy->retire_reason,
        ];
    }

    public function find(int $overtimeRequestId): ?OvertimeRequest
    {
        return OvertimeRequest::query()
            ->whereKey($overtimeRequestId)
            ->first();
    }

    /**
     * @param  list<int>|null  $employmentIds  null means the whole company;
     *                                         an empty list means no employments.
     */
    public function project(
        ?int $employmentId,
        ?array $employmentIds,
        string $asOf,
        ?string $status,
        ?string $from,
        ?string $to,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = OvertimeRequest::query()
            ->where('business_date', '<=', $asOf);

        if ($employmentId !== null) {
            $query->where('employment_id', $employmentId);
        } elseif ($employmentIds !== null) {
            $query->whereIn('employment_id', $employmentIds === [] ? [-1] : array_values($employmentIds));
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($from !== null && $from !== '') {
            $query->where('business_date', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where('business_date', '<=', $to);
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (OvertimeRequest $request): array => $this->payload($request))
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
                'resource' => 'overtime_requests',
                'filters' => [
                    'employment_id' => $employmentId,
                    'employment_ids' => $employmentIds,
                    'status' => $status,
                    'from' => $from,
                    'to' => $to,
                ],
            ],
        );
    }

    /**
     * Time-off balance is a projection over TIMEOFF settlements only. No row
     * is written to a balance table, and PAY settlements never contribute.
     */
    public function timeoffBalances(int $employmentId, string $asOf): ProjectionResult
    {
        $ledgerEntries = $this->timeoffLedger->entries($employmentId, $asOf);
        $settlementIds = array_values(array_filter(
            array_map(static fn (TimeoffLedgerEntry $entry): ?int => $entry->overtime_settlement_id, $ledgerEntries),
            static fn (?int $id): bool => $id !== null,
        ));
        $settlements = OvertimeSettlement::query()
            ->whereIn('id', $settlementIds === [] ? [-1] : $settlementIds)
            ->get()
            ->keyBy('id');
        $recordIds = $settlements->pluck('overtime_record_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $records = OvertimeRecord::query()
            ->whereIn('id', $recordIds === [] ? [-1] : $recordIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ledgerEntries as $entry) {
            $settlement = $entry->overtime_settlement_id !== null
                ? $settlements->get((int) $entry->overtime_settlement_id)
                : null;
            $record = $settlement !== null ? $records->get((int) $settlement->overtime_record_id) : null;
            $items[] = [
                'ledger_entry_id' => (int) $entry->id,
                'settlement_id' => $settlement !== null ? (int) $settlement->id : null,
                'overtime_record_id' => $settlement !== null ? (int) $settlement->overtime_record_id : null,
                'employment_id' => (int) $entry->employment_id,
                'business_date' => $record instanceof OvertimeRecord ? $record->business_date->format('Y-m-d') : null,
                'method' => 'TIMEOFF',
                'entry_type' => $entry->entry_type === TimeoffLedgerEntry::TYPE_EARN ? 'EARNED' : $entry->entry_type,
                'minutes' => (int) $entry->quantity_minutes,
                'reason' => (string) $entry->reason,
                'expires_at' => null,
                'recorded_at' => $entry->recorded_at?->toIso8601String(),
            ];
        }

        return new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: 100,
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'timeoff_ledger_entries',
                'filters' => ['employment_id' => $employmentId],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(OvertimeRequest $request): array
    {
        $employment = $this->employments->snapshot(
            (int) $request->employment_id,
            $request->business_date->format('Y-m-d'),
        );
        $settlement = $request->overtime_record_id !== null
            ? OvertimeSettlement::query()
                ->where('overtime_record_id', (int) $request->overtime_record_id)
                ->first()
            : null;
        $attendance = AttendanceDay::query()
            ->where('employment_id', (int) $request->employment_id)
            ->where('business_date', $request->business_date->format('Y-m-d'))
            ->first();
        $policySnapshot = $request->policy_snapshot_json;

        return [
            'id' => (int) $request->id,
            'employment_id' => (int) $request->employment_id,
            'employee_no' => $employment?->employeeNo,
            'employee_name' => $employment !== null && $employment->employeeName !== '' ? $employment->employeeName : null,
            'business_date' => $request->business_date->format('Y-m-d'),
            'declared_minutes' => (int) $request->declared_minutes,
            'confirmed_minutes' => $request->confirmed_minutes !== null ? (int) $request->confirmed_minutes : null,
            'status' => (string) $request->status,
            'reason' => (string) $request->reason,
            'case_id' => $request->case_id !== null ? (int) $request->case_id : null,
            'overtime_record_id' => $request->overtime_record_id !== null ? (int) $request->overtime_record_id : null,
            'policy_version_id' => $request->policy_version_id !== null ? (int) $request->policy_version_id : null,
            'policy_snapshot' => is_array($policySnapshot) ? $policySnapshot : null,
            'settlement_method' => $settlement?->method,
            'settlement_id' => $settlement?->id !== null ? (int) $settlement->id : null,
            'attendance_status' => $attendance?->status,
            'attendance_worked_minutes' => $attendance !== null ? (int) $attendance->worked_minutes : null,
            'attendance_overtime_minutes' => $attendance !== null ? (int) $attendance->overtime_minutes : null,
            'payroll_period_frozen' => $this->payrollFrozen(
                (int) $request->employment_id,
                $request->business_date->format('Y-m'),
            ),
            'recorded_at' => $request->recorded_at?->toIso8601String(),
        ];
    }

    private function multiplier(mixed $value, string $field): string
    {
        $multiplier = is_string($value) ? trim($value) : '';

        if (preg_match(self::MULTIPLIER_PATTERN, $multiplier) !== 1) {
            throw new ValidationException([
                $field => ['The multiplier must be a fixed-point string with 6-18 decimal places.'],
            ]);
        }

        if (Decimal::fromString($multiplier)->compare(Decimal::fromString('0')) <= 0) {
            throw new ValidationException([$field => ['The multiplier must be greater than zero.']]);
        }

        return $multiplier;
    }

    private function payrollFrozen(int $employmentId, string $period): ?bool
    {
        if (! $this->container->bound(PayrollPeriodLockReader::class)) {
            return null;
        }

        try {
            $reader = $this->container->make(PayrollPeriodLockReader::class);

            if (! $reader instanceof PayrollPeriodLockReader) {
                return null;
            }

            return $reader->isFrozen($employmentId, $period);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollRunMember;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\FieldSet;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Money\Decimal;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Run creation and scheme management. Payroll-item writes live in
 * PayrollItemService; calculations are delegated to CalculationService.
 */
final class PayrollRunService
{
    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly PayrollStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly PayrollRunMembershipDirectory $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRun(int $actorUserId, array $data): PayrollRun
    {
        $period = (string) $data['period'];

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new ValidationException(['period' => ['The period must be YYYY-MM.']]);
        }

        $scheme = PayrollScheme::query()
            ->whereKey((int) $data['scheme_id'])
            ->first();

        if ($scheme === null) {
            throw new ValidationException(['scheme_id' => ['The payroll scheme does not exist.']]);
        }

        if ((string) $scheme->status !== 'ACTIVE') {
            throw new ConflictException('The payroll scheme is not active.', ['reason' => 'SCHEME_INACTIVE']);
        }

        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $payDate = (string) ($data['pay_date'] ?? $end);
        $runType = PayrollRunType::from((string) ($data['run_type'] ?? 'REGULAR'));

        if ($runType === PayrollRunType::CORRECTION) {
            throw new ValidationException(['run_type' => ['Correction runs must be created through the corrections endpoint.']]);
        }

        $existing = PayrollRun::query()
            ->where('period', $period)
            ->where('run_type', $runType->value)
            ->whereNotIn('status', [PayrollRunStatus::CANCELLED->value])
            ->first();

        if ($existing !== null) {
            throw new ConflictException('A payroll run already exists for this period.', [
                'reason' => 'PAYROLL_RUN_ALREADY_EXISTS',
                'run_id' => (int) $existing->id,
            ]);
        }

        return DB::transaction(function () use ($actorUserId, $data, $scheme, $period, $start, $end, $payDate, $runType): PayrollRun {
            $run = PayrollRun::query()->create([
                'scheme_id' => $scheme->id,
                'period' => $period,
                'earning_period_start' => $start,
                'earning_period_end' => $end,
                'posting_period' => (string) ($data['posting_period'] ?? $period),
                'pay_date' => $payDate,
                'cutoff_at' => $this->clock->now(),
                'status' => PayrollRunStatus::DRAFT,
                'run_type' => $runType,
                'correction_of_run_id' => null,
                'prepared_by' => $actorUserId,
                'rule_version' => (string) ($data['rule_version'] ?? 'DEMO-V1'),
                'snapshot_version' => 1,
            ]);

            PayrollRunMember::query()->create([
                'run_id' => (int) $run->id,
                'user_id' => $actorUserId,
                'member_role' => PayrollRunMember::ROLE_PREPARER,
                'assigned_by' => $actorUserId,
                'reason' => 'Run creator',
                'valid_from' => $this->clock->now(),
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.created',
                aggregateType: 'payroll_run',
                aggregateId: (int) $run->id,
                after: ['status' => PayrollRunStatus::DRAFT->value, 'period' => $period],
            );

            return $run->refresh();
        });
    }

    /**
     * DRAFT -> INPUT_LOCKED with immutable snapshots and a stable hash.
     */
    public function lockInputs(int $runId, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
            $status = $run->status instanceof PayrollRunStatus ? $run->status : PayrollRunStatus::from((string) $run->status);

            if ($status === PayrollRunStatus::INPUT_LOCKED) {
                return $run;
            }

            if ($status !== PayrollRunStatus::DRAFT) {
                throw new ConflictException('Inputs can only be locked from DRAFT.', [
                    'reason' => 'RUN_NOT_LOCKABLE',
                    'status' => $status->value,
                ]);
            }

            $run = $this->stateMachine->transition($run, PayrollRunStatus::INPUT_LOCKED, []);
            $hash = $this->snapshots->lock($run, $actorUserId);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.inputs_locked',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => PayrollRunStatus::DRAFT->value],
                after: ['status' => PayrollRunStatus::INPUT_LOCKED->value, 'input_hash' => $hash],
            );

            return $run->refresh();
        });
    }

    /**
     * Assign a run member before review/approval/publication. The actor must
     * already belong to the run; the HTTP route separately gates the prepare
     * capability on the PAYROLL_RUN anchor.
     *
     * @return array{run_id:int,user_id:int,member_role:string,assigned_by:int,reason:string,valid_from:string}
     */
    public function assignMember(
        int $runId,
        int $actorUserId,
        int $targetUserId,
        string $memberRole,
        string $reason,
    ): array {
        $memberRole = strtoupper(trim($memberRole));
        $reason = trim($reason);

        if (! in_array($memberRole, PayrollRunMember::ROLES, true) || $memberRole === PayrollRunMember::ROLE_PREPARER) {
            throw new ValidationException(['member_role' => ['The payroll member role is invalid.']]);
        }

        if ($targetUserId < 1 || $reason === '') {
            throw new ValidationException(['member' => ['Target user and reason are required.']]);
        }

        return DB::transaction(function () use ($runId, $actorUserId, $targetUserId, $memberRole, $reason): array {
            $run = $this->stateMachine->lockRun($runId);
            $actorIsMember = PayrollRunMember::query()
                ->where('run_id', $runId)
                ->where('user_id', $actorUserId)
                ->exists() || (int) $run->prepared_by === $actorUserId;

            if (! $actorIsMember) {
                throw new NotFoundOrForbiddenException;
            }

            $member = PayrollRunMember::query()->updateOrCreate(
                ['run_id' => $runId, 'user_id' => $targetUserId, 'member_role' => $memberRole],
                [
                    'assigned_by' => $actorUserId,
                    'reason' => mb_substr($reason, 0, 500),
                    'valid_from' => $this->clock->now(),
                    'updated_at' => $this->clock->now(),
                ],
            );

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.member.assigned',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                after: [
                    'user_id' => $targetUserId,
                    'member_role' => $memberRole,
                    'reason' => $reason,
                ],
            );

            return [
                'run_id' => (int) $member->run_id,
                'user_id' => (int) $member->user_id,
                'member_role' => (string) $member->member_role,
                'assigned_by' => (int) $member->assigned_by,
                'reason' => (string) $member->reason,
                'valid_from' => $member->valid_from->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * @param  list<int>  $runIds
     * @return list<PayrollRun>
     */
    public function runsByIds(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        return PayrollRun::query()
            ->whereIn('id', $runIds)
            ->get()
            ->all();
    }

    public function findRun(int $runId): PayrollRun
    {
        $run = PayrollRun::query()->whereKey($runId)->first();

        if ($run === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function listRuns(int $userId, string $businessDate, array $filters, int $page, int $pageSize): array
    {
        // Payroll runs are PAYROLL_RUN-anchored: the index may only list runs the
        // principal is a member of (preparer, reviewer, approver or publisher).
        // Without this constraint a COMPANY-wide route guard would expose every
        // run to anyone holding payroll.run.read at any anchor.
        $memberRunIds = $this->membership->runIdsForUser($userId, $businessDate);

        $query = PayrollRun::query()
            ->withCount('employees')
            ->whereIn('id', $memberRunIds)
            ->when(isset($filters['period']), fn ($q) => $q->where('period', $filters['period']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('period')
            ->orderByDesc('id');

        $total = (int) $query->count();
        $rows = $query->forPage($page, $pageSize)->get();

        return [
            'items' => $rows->map(fn (PayrollRun $run): array => $this->runSummary($run))->all(),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runDetail(PayrollRun $run, bool $includeAmounts = true): array
    {
        $employees = PayrollRunEmployee::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->get();

        return [
            ...$this->runSummary($run, $includeAmounts),
            'scheme_id' => (int) $run->scheme_id,
            'earning_period_start' => $this->dateOnly($run->earning_period_start),
            'earning_period_end' => $this->dateOnly($run->earning_period_end),
            'posting_period' => (string) $run->posting_period,
            'cutoff_at' => $run->cutoff_at->toIso8601ZuluString(),
            'prepared_by' => (int) $run->prepared_by,
            'reviewed_by' => $run->reviewed_by,
            'approved_by' => $run->approved_by,
            'published_by' => $run->published_by,
            'input_hash' => $run->input_hash,
            'snapshot_version' => (int) $run->snapshot_version,
            'reopen_count' => (int) $run->reopen_count,
            'employee_count' => $employees->count(),
            'members' => PayrollRunMember::query()
                ->where('run_id', $run->id)
                ->orderBy('id')
                ->get()
                ->map(static fn (PayrollRunMember $member): array => [
                    'user_id' => (int) $member->user_id,
                    'member_role' => (string) $member->member_role,
                    'assigned_by' => (int) $member->assigned_by,
                    'reason' => (string) $member->reason,
                ])->all(),
            'employees' => $employees->map(function (PayrollRunEmployee $employee) use ($includeAmounts): array {
                $row = [
                    'employment_id' => (int) $employee->employment_id,
                    'status' => $employee->status->value,
                    'failure_reason' => $employee->failure_reason,
                ];

                if ($includeAmounts) {
                    $row['gross_cents'] = (int) $employee->gross_cents;
                    $row['taxable_cents'] = (int) $employee->taxable_cents;
                    $row['deductions_cents'] = (int) $employee->deductions_cents;
                    $row['net_cents'] = (int) $employee->net_cents;
                    $row['employer_cost_cents'] = (int) $employee->employer_cost_cents;
                }

                return $row;
            })->all(),
        ];
    }

    /**
     * Immutable input evidence for a run, projected through the caller's
     * payslip field set. Money keys disappear entirely when the caller does
     * not hold P3; they are never replaced with zeroes or nulls.
     *
     * @return list<array<string, mixed>>
     */
    public function inputSnapshots(PayrollRun $run, FieldSet $fields): array
    {
        $moneyKeys = [
            'base_salary_minor', 'gross_cents', 'net_cents', 'deductions_cents',
            'taxable_cents', 'employer_cost_cents', 'employee_si_cents',
            'employee_hf_cents', 'tax_cents',
        ];
        $showAmounts = $fields->isVisible('net');

        return PayrollInputSnapshot::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->orderBy('snapshot_type')
            ->orderBy('snapshot_version')
            ->get()
            ->map(static function (PayrollInputSnapshot $snapshot) use ($moneyKeys, $showAmounts): array {
                $payload = (array) $snapshot->payload_json;

                if (! $showAmounts) {
                    foreach ($moneyKeys as $key) {
                        unset($payload[$key]);
                    }
                }

                return [
                    'id' => (int) $snapshot->id,
                    'employment_id' => (int) $snapshot->employment_id,
                    'snapshot_type' => (string) $snapshot->snapshot_type,
                    'snapshot_version' => (int) $snapshot->snapshot_version,
                    'source_hash' => (string) $snapshot->source_hash,
                    'payload' => $payload,
                    'created_at' => $snapshot->created_at?->toIso8601ZuluString(),
                ];
            })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employees(PayrollRun $run, FieldSet $fields): array
    {
        return PayrollRunEmployee::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->get()
            ->map(function (PayrollRunEmployee $employee) use ($fields): array {
                // Money keys are projected through the caller's field set and
                // disappear entirely when the caller only holds run-read.
                $row = [
                    'employment_id' => (int) $employee->employment_id,
                    'status' => $employee->status->value,
                    'failure_reason' => $employee->failure_reason,
                ];

                // The projection schema names amounts gross/net/deductions.
                // The API keeps its established *_cents keys, so decide
                // visibility from the schema and emit the API key only when
                // the field is visible.
                $amounts = [
                    'gross' => 'gross_cents',
                    'net' => 'net_cents',
                    'deductions' => 'deductions_cents',
                    'taxable' => 'taxable_cents',
                    'employer_cost' => 'employer_cost_cents',
                    'employee_si' => 'employee_si_cents',
                    'employee_hf' => 'employee_hf_cents',
                    'tax' => 'tax_cents',
                ];

                foreach ($amounts as $schemaField => $apiKey) {
                    if ($fields->isVisible($schemaField)) {
                        $row[$apiKey] = (int) $employee->{$apiKey};
                    }
                }

                return $row;
            })->all();
    }

    /**
     * Legacy service seam retained for console fixtures. HTTP writes go
     * through PayrollItemService so the identity/version split and the
     * append-only/retire commands stay in one place.
     *
     * @param  array<string, mixed>  $data
     */
    public function createItem(int $actorUserId, array $data): PayrollItem
    {
        $version = app(PayrollItemService::class)->create($actorUserId, $data);

        return PayrollItem::query()->whereKey((int) $version->payroll_item_id)->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return app(PayrollItemService::class)->items();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createScheme(int $actorUserId, array $data): PayrollScheme
    {
        return DB::transaction(function () use ($actorUserId, $data): PayrollScheme {
            $code = strtoupper((string) $data['code']);
            $validFrom = (string) ($data['valid_from'] ?? $this->clock->now()->toDateString());
            app(PayrollItemService::class)->assertPeriodNotFrozen(substr($validFrom, 0, 7), 'PAYROLL_RUN_FROZEN');

            // Server-owned monotonic version. The client value is ignored so
            // a stale tab cannot overwrite or collide with a scheme version.
            $latest = PayrollScheme::query()
                ->where('code', $code)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $version = $latest !== null ? ((int) $latest->version) + 1 : 1;

            $scheme = PayrollScheme::query()->create([
                'code' => $code,
                'name' => (string) $data['name'],
                'frequency' => 'MONTHLY',
                'currency' => strtoupper((string) $data['currency']),
                'version' => $version,
                'status' => 'ACTIVE',
                'valid_from' => $validFrom,
                'valid_to' => (string) ($data['valid_to'] ?? '9999-12-31'),
                'proration_divisor' => (string) ($data['proration_divisor'] ?? 'ANNUAL_DAYS_365'),
                'rounding_mode' => (string) ($data['rounding_mode'] ?? 'HALF_UP'),
                'overtime_multiplier' => (string) ($data['overtime_multiplier'] ?? '1.500000'),
                'allow_negative_net' => (bool) ($data['allow_negative_net'] ?? false),
                'base_salary_cents' => (int) ($data['base_salary_cents'] ?? 0),
            ]);

            foreach ((array) ($data['items'] ?? []) as $position => $itemData) {
                $itemData = (array) $itemData;
                PayrollSchemeItem::query()->create([
                    'scheme_id' => $scheme->id,
                    'payroll_item_id' => isset($itemData['payroll_item_id']) ? (int) $itemData['payroll_item_id'] : null,
                    'item_code' => strtoupper((string) $itemData['item_code']),
                    'label' => (string) ($itemData['label'] ?? $itemData['item_code']),
                    'category' => (string) ($itemData['category'] ?? 'EARNING'),
                    'calculation' => (string) ($itemData['calculation'] ?? 'FIXED'),
                    'amount_cents' => (int) ($itemData['amount_cents'] ?? 0),
                    'rate_scaled' => $this->rateScaled($itemData),
                    'basis' => (string) ($itemData['basis'] ?? 'BASE_SALARY'),
                    'sort_order' => (int) ($itemData['sort_order'] ?? ($position + 1) * 10),
                    'taxable' => (bool) ($itemData['taxable'] ?? true),
                ]);
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.scheme.created',
                aggregateType: 'payroll_scheme',
                aggregateId: (int) $scheme->id,
                after: ['code' => $scheme->code, 'version' => (int) $scheme->version],
            );

            return $scheme->load('items');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schemes(): array
    {
        return PayrollScheme::query()
            ->with('items')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(fn (PayrollScheme $scheme): array => [
                'id' => (int) $scheme->id,
                'code' => (string) $scheme->code,
                'name' => (string) $scheme->name,
                'currency' => (string) $scheme->currency,
                'version' => (int) $scheme->version,
                'status' => (string) $scheme->status,
                'proration_divisor' => (string) $scheme->proration_divisor,
                'rounding_mode' => (string) $scheme->rounding_mode,
                'overtime_multiplier' => (string) $scheme->overtime_multiplier,
                'base_salary_cents' => (int) $scheme->base_salary_cents,
                'items' => $scheme->items->map(static fn (PayrollSchemeItem $item): array => [
                    'item_code' => (string) $item->item_code,
                    'label' => (string) $item->label,
                    'category' => (string) $item->category,
                    'amount_cents' => (int) $item->amount_cents,
                    'sort_order' => (int) $item->sort_order,
                    'taxable' => (bool) $item->taxable,
                ])->all(),
            ])
            ->all();
    }

    /**
     * Exact decimal-string conversion at scale 1e6. Clients may send either the
     * already-scaled integer or a rate such as "0.125000"; arrays/booleans are
     * rejected rather than coerced through a float.
     *
     * @param  array<string, mixed>  $itemData
     */
    private function rateScaled(array $itemData): ?int
    {
        if (isset($itemData['rate_scaled'])) {
            return (int) $itemData['rate_scaled'];
        }

        if (! isset($itemData['rate']) || ! is_string($itemData['rate'])) {
            return null;
        }

        $decimal = Decimal::fromString($itemData['rate']);

        return $decimal->toScaledInt(6);
    }

    private function dateOnly(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    public function runSummary(PayrollRun $run, bool $includeAmounts = true): array
    {
        $summary = [
            'id' => (int) $run->id,
            'period' => (string) $run->period,
            'run_type' => $run->run_type->value,
            'status' => $run->status->value,
            'correction_of_run_id' => $run->correction_of_run_id,
            'pay_date' => $this->dateOnly($run->pay_date),
            'employee_count' => $this->employeeCount($run),
            'created_at' => $run->created_at->toIso8601ZuluString(),
        ];

        if ($includeAmounts) {
            $summary['gross_cents'] = (int) $run->gross_cents;
            $summary['deductions_cents'] = (int) $run->deductions_cents;
            $summary['net_cents'] = (int) $run->net_cents;
            $summary['employer_cost_cents'] = (int) $run->employer_cost_cents;
        }

        return $summary;
    }

    /**
     * The list query eager-loads the employee count; detail rendering falls
     * back to one bounded count when the run is not part of a list query.
     */
    private function employeeCount(PayrollRun $run): int
    {
        $loaded = $run->getAttribute('employees_count');

        if (is_numeric($loaded)) {
            return (int) $loaded;
        }

        return PayrollRunEmployee::query()->where('run_id', $run->id)->count();
    }
}

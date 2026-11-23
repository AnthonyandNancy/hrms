<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollParameterSet;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Modules\Payroll\Models\Payslip;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Run creation and catalog management. This class is the only writer of
 * payroll_schemes / payroll_items through the HTTP surface; calculations are
 * delegated to CalculationService.
 */
final class PayrollRunService
{
    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly PayrollStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRun(int $tenantId, int $actorUserId, array $data): PayrollRun
    {
        $period = (string) $data['period'];

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new ValidationException(['period' => ['The period must be YYYY-MM.']]);
        }

        $scheme = PayrollScheme::query()
            ->where('tenant_id', $tenantId)
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
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $scheme->legal_entity_id)
            ->where('period', $period)
            ->where('run_type', $runType->value)
            ->whereNotIn('status', [PayrollRunStatus::CANCELLED->value])
            ->first();

        if ($existing !== null) {
            throw new ConflictException('A payroll run already exists for this legal entity and period.', [
                'reason' => 'PAYROLL_RUN_ALREADY_EXISTS',
                'run_id' => (int) $existing->id,
            ]);
        }

        return DB::transaction(function () use ($tenantId, $actorUserId, $data, $scheme, $period, $start, $end, $payDate, $runType): PayrollRun {
            $run = PayrollRun::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $scheme->legal_entity_id,
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

            $this->audit->record(
                tenantId: $tenantId,
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
    public function lockInputs(int $tenantId, int $runId, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
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
                tenantId: $tenantId,
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

    public function findRun(int $tenantId, int $runId): PayrollRun
    {
        $run = PayrollRun::query()->where('tenant_id', $tenantId)->whereKey($runId)->first();

        if ($run === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function listRuns(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = PayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['period']), fn ($q) => $q->where('period', $filters['period']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['legal_entity_id']), fn ($q) => $q->where('legal_entity_id', (int) $filters['legal_entity_id']))
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
    public function runDetail(PayrollRun $run): array
    {
        $employees = PayrollRunEmployee::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->get();

        return [
            ...$this->runSummary($run),
            'scheme_id' => (int) $run->scheme_id,
            'earning_period_start' => (string) $run->earning_period_start,
            'earning_period_end' => (string) $run->earning_period_end,
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
            'employees' => $employees->map(fn (PayrollRunEmployee $employee): array => [
                'employment_id' => (int) $employee->employment_id,
                'status' => $employee->status->value,
                'gross_cents' => (int) $employee->gross_cents,
                'taxable_cents' => (int) $employee->taxable_cents,
                'deductions_cents' => (int) $employee->deductions_cents,
                'net_cents' => (int) $employee->net_cents,
                'employer_cost_cents' => (int) $employee->employer_cost_cents,
                'failure_reason' => $employee->failure_reason,
            ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employees(PayrollRun $run): array
    {
        return PayrollRunEmployee::query()
            ->where('run_id', $run->id)
            ->orderBy('employment_id')
            ->get()
            ->map(fn (PayrollRunEmployee $employee): array => [
                'employment_id' => (int) $employee->employment_id,
                'status' => $employee->status->value,
                'gross_cents' => (int) $employee->gross_cents,
                'taxable_cents' => (int) $employee->taxable_cents,
                'deductions_cents' => (int) $employee->deductions_cents,
                'net_cents' => (int) $employee->net_cents,
                'employer_cost_cents' => (int) $employee->employer_cost_cents,
                'employee_si_cents' => (int) $employee->employee_si_cents,
                'employee_hf_cents' => (int) $employee->employee_hf_cents,
                'tax_cents' => (int) $employee->tax_cents,
                'failure_reason' => $employee->failure_reason,
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(int $tenantId, int $actorUserId, array $data): PayrollItem
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $data): PayrollItem {
            $item = PayrollItem::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => (int) $data['legal_entity_id'],
                'code' => strtoupper((string) $data['code']),
                'name' => (string) $data['name'],
                'type' => (string) $data['type'],
                'taxable' => (bool) ($data['taxable'] ?? true),
                'insurable' => (bool) ($data['insurable'] ?? true),
                'calculation' => (string) ($data['calculation'] ?? 'FIXED'),
                'formula_json' => $data['formula_json'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'status' => 'ACTIVE',
            ]);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.item.created',
                aggregateType: 'payroll_item',
                aggregateId: (int) $item->id,
                after: ['code' => $item->code, 'type' => $item->type->value],
            );

            return $item;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $tenantId, ?int $legalEntityId): array
    {
        return PayrollItem::query()
            ->where('tenant_id', $tenantId)
            ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (PayrollItem $item): array => [
                'id' => (int) $item->id,
                'legal_entity_id' => (int) $item->legal_entity_id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'type' => $item->type->value,
                'taxable' => (bool) $item->taxable,
                'insurable' => (bool) $item->insurable,
                'calculation' => (string) $item->calculation,
                'sort_order' => (int) $item->sort_order,
                'status' => (string) $item->status,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createScheme(int $tenantId, int $actorUserId, array $data): PayrollScheme
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $data): PayrollScheme {
            $scheme = PayrollScheme::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => (int) $data['legal_entity_id'],
                'code' => strtoupper((string) $data['code']),
                'name' => (string) $data['name'],
                'frequency' => 'MONTHLY',
                'currency' => strtoupper((string) $data['currency']),
                'version' => (int) ($data['version'] ?? 1),
                'status' => 'ACTIVE',
                'valid_from' => (string) ($data['valid_from'] ?? now()->toDateString()),
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
                    'tenant_id' => $tenantId,
                    'scheme_id' => $scheme->id,
                    'payroll_item_id' => isset($itemData['payroll_item_id']) ? (int) $itemData['payroll_item_id'] : null,
                    'item_code' => strtoupper((string) $itemData['item_code']),
                    'label' => (string) ($itemData['label'] ?? $itemData['item_code']),
                    'category' => (string) ($itemData['category'] ?? 'EARNING'),
                    'calculation' => (string) ($itemData['calculation'] ?? 'FIXED'),
                    'amount_cents' => (int) ($itemData['amount_cents'] ?? 0),
                    'rate_scaled' => isset($itemData['rate_scaled']) ? (int) $itemData['rate_scaled'] : null,
                    'basis' => (string) ($itemData['basis'] ?? 'BASE_SALARY'),
                    'sort_order' => (int) ($itemData['sort_order'] ?? ($position + 1) * 10),
                    'taxable' => (bool) ($itemData['taxable'] ?? true),
                ]);
            }

            $this->audit->record(
                tenantId: $tenantId,
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
    public function schemes(int $tenantId, ?int $legalEntityId): array
    {
        return PayrollScheme::query()
            ->where('tenant_id', $tenantId)
            ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->with('items')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(fn (PayrollScheme $scheme): array => [
                'id' => (int) $scheme->id,
                'legal_entity_id' => (int) $scheme->legal_entity_id,
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
     * @return array<string, mixed>
     */
    public function runSummary(PayrollRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'legal_entity_id' => (int) $run->legal_entity_id,
            'period' => (string) $run->period,
            'run_type' => $run->run_type->value,
            'status' => $run->status->value,
            'correction_of_run_id' => $run->correction_of_run_id,
            'pay_date' => (string) $run->pay_date,
            'gross_cents' => (int) $run->gross_cents,
            'deductions_cents' => (int) $run->deductions_cents,
            'net_cents' => (int) $run->net_cents,
            'employer_cost_cents' => (int) $run->employer_cost_cents,
            'created_at' => $run->created_at->toIso8601ZuluString(),
        ];
    }
}

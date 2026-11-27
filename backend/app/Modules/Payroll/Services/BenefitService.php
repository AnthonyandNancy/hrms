<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\BenefitEnrollment;
use App\Modules\Payroll\Models\BenefitPlan;
use App\Modules\Payroll\Models\BenefitTransaction;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statutory benefit plans, effective-dated enrollments and the monthly cost
 * ledger. Rates are exact decimal strings; money is integer minor units only.
 */
final class BenefitService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PeopleDirectory $people,
        private readonly CompanyBusinessDate $businessDates,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
        private readonly PayrollRunService $payrollRuns,
    ) {}

    /**
     * @param  array{code?: string|null}  $filters
     * @return array{rows: Collection<int, BenefitPlan>, total: int, as_of: string}
     */
    public function listPlans(Principal $principal, array $filters, string $asOf, int $page, int $pageSize): array
    {
        $query = BenefitPlan::query();

        if (($filters['code'] ?? null) !== null) {
            $query->where('code', (string) $filters['code']);
        }

        $total = (int) (clone $query)->count();

        return [
            'rows' => $query->orderBy('code')->orderByDesc('version')->forPage($page, $pageSize)->get(),
            'total' => $total,
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  array{code: string, name: string, employer_rate: string, employee_rate: string, effective_from: string, effective_to?: string|null}  $data
     */
    public function createPlan(int $actorUserId, array $data): BenefitPlan
    {
        $employerRate = $this->normalizeRate((string) $data['employer_rate'], 'employer_rate');
        $employeeRate = $this->normalizeRate((string) $data['employee_rate'], 'employee_rate');
        $code = strtoupper(trim((string) $data['code']));
        $effectiveFrom = (string) $data['effective_from'];
        $effectiveTo = isset($data['effective_to']) ? (string) $data['effective_to'] : null;

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new ValidationException(
                ['effective_to' => ['The effective_to date must not precede effective_from.']],
                'The given data was invalid.',
                ['reason' => 'BENEFIT_EFFECTIVE_RANGE_INVALID'],
            );
        }

        return DB::transaction(function () use ($actorUserId, $data, $code, $employerRate, $employeeRate, $effectiveFrom, $effectiveTo): BenefitPlan {
            $latest = BenefitPlan::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            if ($latest !== null && $effectiveFrom <= (string) $latest->effective_from) {
                throw new ConflictException(
                    'A benefit plan version must start after the latest version.',
                    ['reason' => 'BENEFIT_PLAN_VERSION_ORDER', 'latest_effective_from' => (string) $latest->effective_from],
                );
            }

            $version = $latest === null ? 1 : ((int) $latest->version) + 1;
            $now = $this->clock->now();

            if ($latest !== null && $latest->effective_to === null) {
                $latest->forceFill(['effective_to' => $effectiveFrom])->save();
            }

            $plan = BenefitPlan::query()->create([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'employer_rate' => $employerRate,
                'employee_rate' => $employeeRate,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'version' => $version,
                'created_by' => $actorUserId,
                'created_at' => $now,
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'benefit.plan.versioned',
                aggregateType: 'benefit_plan',
                aggregateId: (int) $plan->id,
                after: [
                    'code' => $code,
                    'version' => $version,
                    'effective_from' => $effectiveFrom,
                    'employer_rate' => $employerRate,
                    'employee_rate' => $employeeRate,
                ],
            );

            return $plan;
        });
    }

    /**
     * @param  array{employment_id?: int|null, plan_id?: int|null, payroll_run_id?: int|null}  $filters
     * @return array{rows: Collection<int, BenefitEnrollment>, total: int}
     */
    public function listEnrollments(array $filters, int $page, int $pageSize): array
    {
        $query = BenefitEnrollment::query();

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', (int) $filters['employment_id']);
        }

        if (($filters['plan_id'] ?? null) !== null) {
            $query->where('plan_id', (int) $filters['plan_id']);
        }

        $total = (int) (clone $query)->count();

        return [
            'rows' => $query->orderBy('employment_id')->orderByDesc('effective_from')->orderByDesc('id')->forPage($page, $pageSize)->get(),
            'total' => $total,
        ];
    }

    /**
     * @param  array{plan_id: int, employment_id: int, base_amount_cents: int, effective_from: string, effective_to?: string|null}  $data
     */
    public function createEnrollment(Principal $principal, array $data): BenefitEnrollment
    {
        $businessDate = $this->businessDates->today()->value();
        $employmentId = (int) $data['employment_id'];
        $planId = (int) $data['plan_id'];
        $baseAmount = (int) $data['base_amount_cents'];

        if ($baseAmount < 0) {
            throw new ValidationException(
                ['base_amount_cents' => ['The statutory base must be a non-negative integer minor amount.']],
                'The given data was invalid.',
                ['reason' => 'BENEFIT_BASE_INVALID'],
            );
        }

        $snapshot = $this->people->employmentSnapshot($employmentId, $businessDate);

        if ($snapshot === null
            || ! $this->authorizer->allows($principal, 'benefit.manage', \App\Shared\Authorization\AnchorRef::company(), $businessDate)) {
            throw new NotFoundOrForbiddenException;
        }

        $plan = BenefitPlan::query()
            ->whereKey($planId)
            ->first();

        if ($plan === null) {
            throw new NotFoundOrForbiddenException;
        }

        $effectiveFrom = (string) $data['effective_from'];
        $effectiveTo = isset($data['effective_to']) ? (string) $data['effective_to'] : null;

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new ValidationException(
                ['effective_to' => ['The effective_to date must not precede effective_from.']],
                'The given data was invalid.',
                ['reason' => 'BENEFIT_EFFECTIVE_RANGE_INVALID'],
            );
        }

        return DB::transaction(function () use ($principal, $snapshot, $plan, $baseAmount, $effectiveFrom, $effectiveTo): BenefitEnrollment {
            /** @var BenefitEnrollment|null $latest */
            $latest = BenefitEnrollment::query()
                ->where('employment_id', $snapshot->employmentId)
                ->where('plan_id', (int) $plan->id)
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($latest !== null && $effectiveFrom <= (string) $latest->effective_from) {
                throw new ConflictException(
                    'An enrollment movement must start after the previous effective date.',
                    ['reason' => 'BENEFIT_ENROLLMENT_VERSION_ORDER', 'latest_effective_from' => (string) $latest->effective_from],
                );
            }

            $version = $latest === null ? 1 : ((int) $latest->version) + 1;

            if ($latest !== null && $latest->effective_to === null) {
                $latest->forceFill(['effective_to' => $effectiveFrom])->save();
            }

            $enrollment = BenefitEnrollment::query()->create([
                'employment_id' => $snapshot->employmentId,
                'plan_id' => (int) $plan->id,
                'base_amount_cents' => $baseAmount,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'version' => $version,
                'created_by' => $principal->userId(),
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $principal->userId(),
                action: 'benefit.enrollment.versioned',
                aggregateType: 'benefit_enrollment',
                aggregateId: (int) $enrollment->id,
                after: [
                    'employment_id' => $snapshot->employmentId,
                    'plan_id' => (int) $plan->id,
                    'base_amount_cents' => $baseAmount,
                    'effective_from' => $effectiveFrom,
                    'version' => $version,
                ],
            );

            return $enrollment;
        });
    }

    /**
     * @param  array{enrollment_id: int, period: string, payroll_run_id?: int|null}  $data
     */
    public function createTransaction(int $actorUserId, array $data): BenefitTransaction
    {
        $period = (string) $data['period'];

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new ValidationException(
                ['period' => ['The period must be YYYY-MM.']],
                'The given data was invalid.',
                ['reason' => 'BENEFIT_PERIOD_INVALID'],
            );
        }

        $enrollment = BenefitEnrollment::query()
            ->whereKey((int) $data['enrollment_id'])
            ->first();

        if ($enrollment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $plan = BenefitPlan::query()
            ->whereKey((int) $enrollment->plan_id)
            ->first();

        if ($plan === null) {
            throw new NotFoundOrForbiddenException;
        }

        $employeeAmount = $this->rateAmount((int) $enrollment->base_amount_cents, (string) $plan->employee_rate);
        $employerAmount = $this->rateAmount((int) $enrollment->base_amount_cents, (string) $plan->employer_rate);
        $payrollRunId = isset($data['payroll_run_id']) ? (int) $data['payroll_run_id'] : null;

        return DB::transaction(function () use ($actorUserId, $enrollment, $period, $employeeAmount, $employerAmount, $payrollRunId): BenefitTransaction {
            $this->assertPayrollRunNotFrozen($payrollRunId);

            if ($payrollRunId !== null && BenefitTransaction::query()
                ->where('payroll_run_id', $payrollRunId)
                ->exists()) {
                throw new ConflictException(
                    'This payroll run is already linked to a benefit transaction.',
                    ['reason' => 'BENEFIT_PAYROLL_LINK_DUPLICATE', 'payroll_run_id' => $payrollRunId],
                );
            }

            if (BenefitTransaction::query()
                ->where('enrollment_id', (int) $enrollment->id)
                ->where('period', $period)
                ->exists()) {
                throw new ConflictException(
                    'This enrollment period is already recorded.',
                    ['reason' => 'BENEFIT_PERIOD_DUPLICATE', 'period' => $period],
                );
            }

            $transaction = BenefitTransaction::query()->create([
                'employment_id' => (int) $enrollment->employment_id,
                'enrollment_id' => (int) $enrollment->id,
                'period' => $period,
                'base_amount_cents' => (int) $enrollment->base_amount_cents,
                'employee_amount_cents' => $employeeAmount,
                'employer_amount_cents' => $employerAmount,
                'total_amount_cents' => $employeeAmount + $employerAmount,
                'payroll_run_id' => $payrollRunId,
                'status' => 'RECORDED',
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'benefit.transaction.recorded',
                aggregateType: 'benefit_transaction',
                aggregateId: (int) $transaction->id,
                after: [
                    'enrollment_id' => (int) $enrollment->id,
                    'period' => $period,
                    'total_amount_cents' => $employeeAmount + $employerAmount,
                    'payroll_run_id' => $payrollRunId,
                ],
            );

            return $transaction;
        });
    }

    /**
     * @param  array{employment_id?: int|null, period?: string|null, enrollment_id?: int|null, payroll_run_id?: int|null}  $filters
     * @return array{rows: Collection<int, BenefitTransaction>, total: int}
     */
    public function listTransactions(array $filters, int $page, int $pageSize): array
    {
        $query = BenefitTransaction::query();

        foreach (['employment_id', 'enrollment_id', 'payroll_run_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, (int) $filters[$field]);
            }
        }

        if (($filters['period'] ?? null) !== null) {
            $query->where('period', (string) $filters['period']);
        }

        $total = (int) (clone $query)->count();

        return [
            'rows' => $query->orderByDesc('period')->orderByDesc('id')->forPage($page, $pageSize)->get(),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollmentPayload(BenefitEnrollment $enrollment, BenefitPlan $plan): array
    {
        return [
            'id' => (int) $enrollment->id,
            'plan_id' => (int) $enrollment->plan_id,
            'employment_id' => (int) $enrollment->employment_id,
            'base_amount_cents' => (int) $enrollment->base_amount_cents,
            'employee_rate' => (string) $plan->employee_rate,
            'employer_rate' => (string) $plan->employer_rate,
            'employee_contribution_cents' => $this->rateAmount((int) $enrollment->base_amount_cents, (string) $plan->employee_rate),
            'employer_contribution_cents' => $this->rateAmount((int) $enrollment->base_amount_cents, (string) $plan->employer_rate),
            'effective_from' => (string) $enrollment->effective_from,
            'effective_to' => $enrollment->effective_to !== null ? (string) $enrollment->effective_to : null,
            'version' => (int) $enrollment->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planPayload(BenefitPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'employer_rate' => (string) $plan->employer_rate,
            'employee_rate' => (string) $plan->employee_rate,
            'effective_from' => (string) $plan->effective_from,
            'effective_to' => $plan->effective_to !== null ? (string) $plan->effective_to : null,
            'version' => (int) $plan->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionPayload(BenefitTransaction $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'enrollment_id' => (int) $transaction->enrollment_id,
            'employment_id' => (int) $transaction->employment_id,
            'period' => (string) $transaction->period,
            'base_amount_cents' => (int) $transaction->base_amount_cents,
            'employee_amount_cents' => (int) $transaction->employee_amount_cents,
            'employer_amount_cents' => (int) $transaction->employer_amount_cents,
            'total_amount_cents' => (int) $transaction->total_amount_cents,
            'payroll_run_id' => $transaction->payroll_run_id !== null ? (int) $transaction->payroll_run_id : null,
            'status' => (string) $transaction->status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function projection(string $asOf, array $items, int $total, int $page, int $pageSize, string $resource): ProjectionResult
    {
        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => $resource],
        );
    }

    /**
     * A published/paid/closed payroll run is a sealed financial fact. The
     * benefit ledger may only link to an in-flight run; a missing run keeps
     * the existing optional-link contract and is rejected later when the
     * caller supplies an invalid reference.
     */
    private function assertPayrollRunNotFrozen(?int $payrollRunId): void
    {
        if ($payrollRunId === null) {
            return;
        }

        try {
            $run = $this->payrollRuns->findRun($payrollRunId);
        } catch (NotFoundOrForbiddenException) {
            return;
        }

        $status = $run->status instanceof PayrollRunStatus
            ? $run->status
            : PayrollRunStatus::from((string) $run->status);

        if ($status->isSealed()) {
            throw new ConflictException('Frozen payroll runs cannot receive benefit transactions.', [
                'reason' => 'BENEFIT_PAYROLL_RUN_FROZEN',
                'payroll_run_id' => $payrollRunId,
            ]);
        }
    }

    private function normalizeRate(string $rate, string $field): string
    {
        $rate = trim($rate);

        if (preg_match('/^(0|1)\.\d{6,18}$/D', $rate) !== 1) {
            throw new ValidationException(
                [$field => ['Rates must be plain decimal strings between 0 and 1 with at least six decimal places.']],
                'The given data was invalid.',
                ['reason' => 'BENEFIT_RATE_SCALE'],
            );
        }

        return $rate;
    }

    private function rateAmount(int $baseCents, string $rate): int
    {
        $amount = Decimal::fromScaledInt($baseCents, 0)
            ->multiply(Decimal::fromString($rate))
            ->toScaledInt(0, RoundingMode::HALF_UP);

        return max(0, $amount);
    }
}

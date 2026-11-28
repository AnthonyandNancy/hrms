<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\EarningLine;
use App\Modules\Payroll\Domain\EmployerCostLine;
use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\ManualAdjustment;
use App\Modules\Payroll\Domain\PayrollCalculationInput;
use App\Modules\Payroll\Domain\PayrollCalculationResult;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollContext;
use App\Modules\Payroll\Domain\PayrollCorrectionDelta;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\PayrollRuleCompiler;
use App\Modules\Payroll\Domain\PayrollRuleEngine;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Modules\Payroll\Domain\RoundingConfig;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Modules\Payroll\Domain\YtdTaxSnapshot;
use App\Modules\Payroll\Enums\PayrollEmployeeStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Money\Decimal;
use App\Shared\Money\Money;
use App\Shared\Money\RoundingMode;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Batch calculation over locked snapshots. The pure PayrollCalculator remains
 * the only arithmetic engine; this service resolves persisted inputs, stores
 * traceable lines and computes correction deltas.
 */
final class CalculationService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly SnapshotService $snapshots,
        private readonly TaxParameterResolver $parameters,
        private readonly CompanyProfileReader $company,
        private readonly PayrollStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly PayrollRuleCompiler $ruleCompiler,
        private readonly PayrollRuleEngine $ruleEngine,
    ) {}

    /**
     * @return array{calculated: int, failed: int, blocked: int, gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int}
     */
    public function calculate(PayrollRun $run, int $actorUserId): array
    {
        return DB::transaction(function () use ($run, $actorUserId): array {
            $locked = $this->stateMachine->lockRun((int) $run->id);
            $status = $locked->status instanceof PayrollRunStatus
                ? $locked->status
                : PayrollRunStatus::from((string) $locked->status);

            if ($status !== PayrollRunStatus::INPUT_LOCKED) {
                throw new ConflictException('Only an input-locked run can be calculated; reopen to change inputs.', [
                    'reason' => 'RUN_NOT_CALCULABLE',
                    'status' => $status->value,
                ]);
            }

            $scheme = PayrollScheme::query()
                ->whereKey($locked->scheme_id)
                ->with('items')
                ->firstOrFail();
            $company = $this->company->current();

            $snapshots = PayrollInputSnapshot::query()
                ->where('run_id', $locked->id)
                ->where('snapshot_type', 'INPUT')
                // Reopening creates a new snapshot version. Historical
                // versions remain append-only evidence.
                ->where('snapshot_version', '<=', (int) $locked->snapshot_version)
                ->orderBy('employment_id')
                ->orderByDesc('snapshot_version')
                ->get()
                ->unique('employment_id')
                ->values();

            if ($snapshots->isEmpty()) {
                throw new ConflictException('No locked input snapshots exist for this run.', [
                    'reason' => 'SNAPSHOTS_MISSING',
                ]);
            }

            $totals = [
                'gross_cents' => 0,
                'deductions_cents' => 0,
                'net_cents' => 0,
                'employer_cost_cents' => 0,
            ];
            $calculated = 0;
            $failed = 0;
            $blocked = 0;
            $correctionTargetNet = 0;
            $correctionBaselineNet = 0;
            $isCorrection = ($locked->run_type instanceof PayrollRunType ? $locked->run_type : PayrollRunType::from((string) $locked->run_type)) === PayrollRunType::CORRECTION;

            foreach ($snapshots as $snapshot) {
                $payload = (array) $snapshot->payload_json;
                $blockers = (array) ($payload['blockers'] ?? []);

                if ($blockers !== []) {
                    $this->storeBlocked($locked, $snapshot, $blockers);
                    $blocked++;

                    continue;
                }

                try {
                    $result = $this->calculateOne($locked, $scheme, $company->timezone, (string) $company->currencyCode, $payload);

                    if ($isCorrection) {
                        $stored = $this->storeCorrection($locked, $snapshot, $payload, $result);
                        $lines = $stored['lines'];
                        $amounts = $stored['amounts'];
                        $correctionTargetNet = $this->checkedAdd($correctionTargetNet, (int) $stored['target_net_cents']);
                        $correctionBaselineNet = $this->checkedAdd($correctionBaselineNet, (int) $stored['baseline_net_cents']);
                    } else {
                        $stored = $this->storeRegular($locked, $snapshot, $payload, $result);
                        $lines = $stored['lines'];
                        $amounts = $stored['amounts'];
                    }

                    foreach (['gross_cents', 'deductions_cents', 'net_cents', 'employer_cost_cents'] as $field) {
                        $totals[$field] = $this->checkedAdd($totals[$field], $amounts[$field]);
                    }

                    $calculated++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->storeFailure($locked, $snapshot, $exception->getMessage());
                    $failed++;
                }
            }

            if ($calculated === 0 && $blocked === 0 && $failed === 0) {
                throw new ConflictException('No employee could be calculated; see run employee failure reasons.', [
                    'reason' => 'PAYROLL_CALCULATION_FAILED',
                    'failed' => $failed,
                ]);
            }

            $targetStatus = $blocked > 0 || $failed > 0
                ? PayrollRunStatus::CALCULATED_WITH_BLOCKERS
                : PayrollRunStatus::CALCULATED;

            $transitionColumns = [
                'gross_cents' => $totals['gross_cents'],
                'deductions_cents' => $totals['deductions_cents'],
                'net_cents' => $totals['net_cents'],
                'employer_cost_cents' => $totals['employer_cost_cents'],
                'calculated_at' => $this->clock->now(),
            ];

            if ($isCorrection) {
                $transitionColumns += [
                    'correction_target_net_cents' => $correctionTargetNet,
                    'correction_baseline_net_cents' => $correctionBaselineNet,
                    'correction_delta_net_cents' => $totals['net_cents'],
                    'correction_cumulative_net_cents' => $correctionTargetNet,
                ];
            }

            $locked = $this->stateMachine->transition($locked, $targetStatus, $transitionColumns);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.calculated',
                aggregateType: 'payroll_run',
                aggregateId: (int) $locked->id,
                before: ['status' => PayrollRunStatus::INPUT_LOCKED->value],
                after: [
                    'status' => $targetStatus->value,
                    'input_hash' => (string) $locked->input_hash,
                    'gross_cents' => $totals['gross_cents'],
                    'deductions_cents' => $totals['deductions_cents'],
                    'net_cents' => $totals['net_cents'],
                    'employer_cost_cents' => $totals['employer_cost_cents'],
                    'failed' => $failed,
                    'blocked' => $blocked,
                    'run_type' => $isCorrection ? 'CORRECTION' : 'REGULAR',
                ],
            );

            return ['calculated' => $calculated, 'failed' => $failed, 'blocked' => $blocked, ...$totals];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function calculateOne(PayrollRun $run, PayrollScheme $scheme, string $timezone, string $currency, array $payload): PayrollCalculationResult
    {
        $terms = (array) ($payload['terms'] ?? []);
        $compensation = (array) ($payload['compensation'] ?? []);
        $compensationSalary = isset($compensation['base_salary_cents']) ? (int) $compensation['base_salary_cents'] : null;
        $termSalary = isset($terms['base_salary_cents']) ? (int) $terms['base_salary_cents'] : null;
        $schemeData = (array) ($payload['scheme'] ?? []);
        $baseSalary = $compensationSalary ?? $termSalary ?? (int) ($schemeData['base_salary_cents'] ?? $scheme->base_salary_cents);
        $segments = array_values(array_filter((array) ($payload['compensation_segments'] ?? []), 'is_array'));
        $weightedSegmentSalary = $segments === []
            ? null
            : $this->weightedSegmentSalary($segments, (string) ($payload['earning_period_start'] ?? ''), (string) ($payload['earning_period_end'] ?? ''));

        if ($weightedSegmentSalary !== null) {
            $baseSalary = $weightedSegmentSalary;
        }

        if ($baseSalary < 0) {
            throw new \InvalidArgumentException('Salary cannot be negative.');
        }

        $attendance = $this->snapshots->attendanceSummary($payload);
        $period = PayrollPeriod::fromLabel((string) $run->period);
        $posting = PayrollPeriod::fromLabel((string) $run->posting_period);
        $payDate = new DateTimeImmutable(substr((string) $run->pay_date, 0, 10).' 00:00:00', new DateTimeZone('UTC'));
        $cutoffValue = $run->cutoff_at instanceof \DateTimeInterface ? $run->cutoff_at->format('Y-m-d H:i:s') : substr((string) $run->cutoff_at, 0, 19);
        $cutoff = new DateTimeImmutable($cutoffValue, new DateTimeZone('UTC'));
        $roundingMode = RoundingMode::from((string) ($schemeData['rounding_mode'] ?? $scheme->rounding_mode));

        $context = new PayrollContext(
            currency: $currency,
            timezone: $timezone,
            rounding: new RoundingConfig($roundingMode),
            prorationDivisor: ProrationDivisor::from((string) ($schemeData['proration_divisor'] ?? $scheme->proration_divisor)),
            allowNegativeNet: (bool) ($schemeData['allow_negative_net'] ?? $scheme->allow_negative_net),
        );

        $ytd = (array) ($payload['ytd_opening'] ?? []);
        $taxVersion = (string) ($payload['tax_parameters_version'] ?? '');
        $siVersion = (string) ($payload['social_insurance_parameters_version'] ?? '');
        $hfVersion = (string) ($payload['housing_fund_parameters_version'] ?? '');
        $on = (string) $run->cutoff_at;

        $taxTable = $this->parameters->taxTable($on);
        $si = $this->parameters->insuranceRule('SOCIAL_INSURANCE', $on);
        $hf = $this->parameters->insuranceRule('HOUSING_FUND', $on);

        if ($taxVersion !== '' && $taxTable->version !== $taxVersion) {
            throw new \InvalidArgumentException('The effective tax table does not match the locked parameter version.');
        }

        $allowances = [];
        $oneTimeBonuses = [];
        $otherDeductions = [];
        $otherEmployerCosts = [];
        foreach ((array) ($payload['scheme']['items'] ?? []) as $item) {
            $item = (array) $item;
            $amount = Money::fromMinor($this->schemeItemAmount($item, $baseSalary, $attendance), $currency);
            $source = new PayrollLineSource('payroll_scheme_item', (string) ($run->scheme_id).':'.(string) ($item['item_code'] ?? ''));

            if ($amount->isZero()) {
                continue;
            }

            if (($item['category'] ?? '') === 'EARNING') {
                $allowances[] = new EarningLine(
                    itemCode: (string) $item['item_code'],
                    label: (string) $item['label'],
                    amount: $amount,
                    source: $source,
                    sortOrder: (int) ($item['sort_order'] ?? 0),
                    taxable: (bool) ($item['taxable'] ?? true),
                );
            } elseif (($item['category'] ?? '') === 'DEDUCTION') {
                $otherDeductions[] = new DeductionLine(
                    itemCode: (string) $item['item_code'],
                    label: (string) $item['label'],
                    amount: $amount,
                    source: $source,
                    sortOrder: (int) ($item['sort_order'] ?? 0),
                    taxable: false,
                );
            } elseif (($item['category'] ?? '') === 'EMPLOYER_COST') {
                $otherEmployerCosts[] = new EmployerCostLine(
                    itemCode: (string) $item['item_code'],
                    label: (string) $item['label'],
                    amount: $amount,
                    source: $source,
                    sortOrder: (int) ($item['sort_order'] ?? 0),
                );
            }
        }

        foreach ((array) ($payload['benefits'] ?? []) as $benefit) {
            $benefit = (array) $benefit;
            $amountCents = (int) ($benefit['employee_amount_cents'] ?? 0);
            $employerAmountCents = (int) ($benefit['employer_amount_cents'] ?? 0);

            if ($amountCents < 0 || $employerAmountCents < 0) {
                throw new \InvalidArgumentException('Benefit amounts cannot be negative.');
            }

            if ($amountCents === 0 && $employerAmountCents === 0) {
                continue;
            }

            $source = new PayrollLineSource((string) ($benefit['source_type'] ?? 'benefit'), (string) ($benefit['source_id'] ?? ''));
            $itemCode = (string) ($benefit['item_code'] ?? '');
            $label = (string) ($benefit['label'] ?? '');
            $sortOrder = (int) ($benefit['sort_order'] ?? 800);

            if ($amountCents > 0) {
                $otherDeductions[] = new DeductionLine(
                    itemCode: $itemCode,
                    label: $label,
                    amount: Money::fromMinor($amountCents, $currency),
                    source: $source,
                    sortOrder: $sortOrder,
                    taxable: (bool) ($benefit['taxable'] ?? false),
                );
            }

            if ($employerAmountCents > 0) {
                $otherEmployerCosts[] = new EmployerCostLine(
                    itemCode: $itemCode.'_EMPLOYER',
                    label: $label.'（单位）',
                    amount: Money::fromMinor($employerAmountCents, $currency),
                    source: $source,
                    sortOrder: $sortOrder + 1,
                );
            }
        }

        foreach ((array) ($payload['performance_bonuses'] ?? []) as $bonus) {
            $bonus = (array) $bonus;
            $amountCents = (int) ($bonus['amount_cents'] ?? 0);

            if ($amountCents < 0) {
                throw new \InvalidArgumentException('Performance bonus amount cannot be negative.');
            }

            if ($amountCents === 0) {
                continue;
            }

            $oneTimeBonuses[] = new EarningLine(
                itemCode: (string) ($bonus['item_code'] ?? ''),
                label: (string) ($bonus['label'] ?? ''),
                amount: Money::fromMinor($amountCents, $currency),
                source: new PayrollLineSource((string) ($bonus['source_type'] ?? 'performance'), (string) ($bonus['source_id'] ?? '')),
                sortOrder: (int) ($bonus['sort_order'] ?? 700),
                taxable: (bool) ($bonus['taxable'] ?? true),
            );
        }

        $manual = [];
        foreach ((array) ($payload['manual_adjustments'] ?? []) as $adjustment) {
            $adjustment = (array) $adjustment;
            $manual[] = new ManualAdjustment(
                itemCode: (string) $adjustment['item_code'],
                label: (string) $adjustment['item_code'],
                amount: Money::fromMinor((int) $adjustment['amount_cents'], $currency),
                source: new PayrollLineSource('payroll_adjustment', (string) $adjustment['adjustment_id']),
                sortOrder: 900,
                taxable: true,
            );
        }

        $input = new PayrollCalculationInput(
            employmentId: (int) $payload['employment_id'],
            period: $period,
            postingPeriod: $posting,
            payDate: $payDate,
            cutoffAt: $cutoff,
            context: $context,
            baseSalaryCents: $baseSalary,
            attendance: $attendance,
            ytd: new YtdTaxSnapshot(
                taxYear: $period->year,
                cumulativeTaxableCents: (int) ($ytd['taxable_income_cents'] ?? 0),
                taxWithheldCents: (int) ($ytd['tax_withheld_cents'] ?? 0),
            ),
            taxTable: $taxTable,
            socialInsurance: new SocialInsuranceRule(
                version: $si['version'],
                minBaseCents: $si['min_base_cents'],
                maxBaseCents: $si['max_base_cents'],
                employeeRate: $si['employee_rate'],
                employerRate: $si['employer_rate'],
            ),
            housingFund: new HousingFundRule(
                version: $hf['version'],
                minBaseCents: $hf['min_base_cents'],
                maxBaseCents: $hf['max_base_cents'],
                employeeRate: $hf['employee_rate'],
                employerRate: $hf['employer_rate'],
            ),
            overtimeMultiplier: Decimal::fromString((string) ($schemeData['overtime_multiplier'] ?? $scheme->overtime_multiplier)),
            salarySourceType: $segments !== [] ? 'employee_compensation_segments' : ($compensationSalary !== null ? 'employee_compensation' : (isset($terms['terms_id']) ? 'employment_terms' : 'payroll_scheme')),
            salarySourceId: $segments !== []
                ? implode(',', array_map(static fn (array $segment): string => (string) ($segment['compensation_id'] ?? ''), $segments))
                : ($compensationSalary !== null ? (string) ($compensation['compensation_id'] ?? '') : (isset($terms['terms_id']) ? (string) $terms['terms_id'] : (string) $run->scheme_id)),
            attendanceSourceType: 'time_payroll_input',
            attendanceSourceId: (string) ($payload['attendance']['source_hash'] ?? 'missing'),
            allowances: $allowances,
            oneTimeBonuses: $oneTimeBonuses,
            manualAdjustments: $manual,
            otherDeductions: $otherDeductions,
            otherEmployerCosts: $otherEmployerCosts,
        );

        return $this->calculator->calculate($input);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{amounts: array{gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int}, lines: list<PayslipLine>}
     */
    private function storeRegular(PayrollRun $run, PayrollInputSnapshot $snapshot, array $payload, PayrollCalculationResult $result): array
    {
        $employee = PayrollRunEmployee::query()->updateOrCreate(
            ['run_id' => $run->id, 'employment_id' => $snapshot->employment_id],
            [
                'status' => PayrollEmployeeStatus::CALCULATED,
                'gross_cents' => $result->gross->minor(),
                'taxable_cents' => $result->taxableIncome->minor(),
                'deductions_cents' => $result->employeeDeductions->minor(),
                'net_cents' => $result->net->minor(),
                'employer_cost_cents' => $result->employerCost->minor(),
                'employee_si_cents' => $result->employeeSocialInsurance->minor(),
                'employee_hf_cents' => $result->employeeHousingFund->minor(),
                'employer_si_cents' => $result->employerSocialInsurance->minor(),
                'employer_hf_cents' => $result->employerHousingFund->minor(),
                'tax_cents' => $result->tax->minor(),
                'ytd_taxable_opening_cents' => (int) ($payload['ytd_opening']['taxable_income_cents'] ?? 0),
                'ytd_tax_withheld_opening_cents' => (int) ($payload['ytd_opening']['tax_withheld_cents'] ?? 0),
                'ytd_applied' => false,
                'ytd_applied_at' => null,
                'failure_reason' => null,
            ],
        );

        $payslip = Payslip::query()->firstOrNew([
            'run_id' => $run->id,
            'employment_id' => $snapshot->employment_id,
        ]);
        $payslip->forceFill([
            'version' => 1,
            'gross_cents' => $result->gross->minor(),
            'deductions_cents' => $result->employeeDeductions->minor(),
            'net_cents' => $result->net->minor(),
            'employer_cost_cents' => $result->employerCost->minor(),
            'published_at' => null,
        ])->save();

        $lines = $this->replaceLines($payslip, $result->lines);

        return [
            'amounts' => [
                'gross_cents' => $employee->gross_cents,
                'deductions_cents' => $employee->deductions_cents,
                'net_cents' => $employee->net_cents,
                'employer_cost_cents' => $employee->employer_cost_cents,
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{amounts: array{gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int}, lines: list<PayslipLine>, target_net_cents: int, baseline_net_cents: int}
     */
    private function storeCorrection(PayrollRun $run, PayrollInputSnapshot $snapshot, array $payload, PayrollCalculationResult $revised): array
    {
        $originalRunId = (int) $run->correction_of_run_id;
        $baseline = $this->baseline($run, $originalRunId, (int) $snapshot->employment_id);

        $delta = new PayrollCorrectionDelta(
            gross: Money::fromMinor($revised->gross->minor() - $baseline['gross'], $revised->gross->currency()),
            taxableIncome: Money::fromMinor($revised->taxableIncome->minor() - $baseline['taxable'], $revised->gross->currency()),
            employeeSocialInsurance: Money::fromMinor($revised->employeeSocialInsurance->minor() - $baseline['employee_si'], $revised->gross->currency()),
            employeeHousingFund: Money::fromMinor($revised->employeeHousingFund->minor() - $baseline['employee_hf'], $revised->gross->currency()),
            tax: Money::fromMinor($revised->tax->minor() - $baseline['tax'], $revised->gross->currency()),
            employeeDeductions: Money::fromMinor($revised->employeeDeductions->minor() - $baseline['deductions'], $revised->gross->currency()),
            employerSocialInsurance: Money::fromMinor($revised->employerSocialInsurance->minor() - $baseline['employer_si'], $revised->gross->currency()),
            employerHousingFund: Money::fromMinor($revised->employerHousingFund->minor() - $baseline['employer_hf'], $revised->gross->currency()),
            employerCost: Money::fromMinor($revised->employerCost->minor() - $baseline['employer_cost'], $revised->gross->currency()),
            net: Money::fromMinor($revised->net->minor() - $baseline['net'], $revised->gross->currency()),
        );

        $employee = PayrollRunEmployee::query()->updateOrCreate(
            ['run_id' => $run->id, 'employment_id' => $snapshot->employment_id],
            [
                'status' => PayrollEmployeeStatus::CALCULATED,
                'gross_cents' => $delta->gross->minor(),
                'taxable_cents' => $delta->taxableIncome->minor(),
                'deductions_cents' => $delta->employeeDeductions->minor(),
                'net_cents' => $delta->net->minor(),
                'employer_cost_cents' => $delta->employerCost->minor(),
                'employee_si_cents' => $delta->employeeSocialInsurance->minor(),
                'employee_hf_cents' => $delta->employeeHousingFund->minor(),
                'employer_si_cents' => $delta->employerSocialInsurance->minor(),
                'employer_hf_cents' => $delta->employerHousingFund->minor(),
                'tax_cents' => $delta->tax->minor(),
                'ytd_taxable_opening_cents' => $baseline['ytd_taxable'],
                'ytd_tax_withheld_opening_cents' => $baseline['ytd_withheld'],
                'ytd_applied' => false,
                'ytd_applied_at' => null,
                'failure_reason' => null,
            ],
        );

        $payslip = Payslip::query()->firstOrNew([
            'run_id' => $run->id,
            'employment_id' => $snapshot->employment_id,
        ]);
        $payslip->forceFill([
            'version' => 1,
            'gross_cents' => $delta->gross->minor(),
            'deductions_cents' => $delta->employeeDeductions->minor(),
            'net_cents' => $delta->net->minor(),
            'employer_cost_cents' => $delta->employerCost->minor(),
            'published_at' => null,
        ])->save();

        $lines = $this->replaceDeltaLines($payslip, $revised, $baseline['lines']);

        return [
            'amounts' => [
                'gross_cents' => $employee->gross_cents,
                'deductions_cents' => $employee->deductions_cents,
                'net_cents' => $employee->net_cents,
                'employer_cost_cents' => $employee->employer_cost_cents,
            ],
            'lines' => $lines,
            'target_net_cents' => $revised->net->minor(),
            'baseline_net_cents' => $baseline['net'],
        ];
    }

    /**
     * Baseline is the original plus every prior effective correction. The
     * target snapshot contains all adjustments up to this correction, so the
     * delta subtracts original + prior corrections rather than original alone.
     *
     * @return array{gross: int, taxable: int, employee_si: int, employee_hf: int, tax: int, deductions: int, employer_si: int, employer_hf: int, employer_cost: int, net: int, ytd_taxable: int, ytd_withheld: int, lines: array<string, array{category: string, amount: int}>}
     */
    private function baseline(PayrollRun $correction, int $originalRunId, int $employmentId): array
    {
        $original = PayrollRun::query()
            ->whereKey($originalRunId)
            ->firstOrFail();
        $originalPayslip = Payslip::query()
            ->where('run_id', $originalRunId)
            ->where('employment_id', $employmentId)
            ->first();

        if ($originalPayslip === null) {
            throw new ConflictException('The original run has no payslip for this employment.', [
                'reason' => 'CORRECTION_BASELINE_MISSING',
                'original_run_id' => $originalRunId,
                'employment_id' => $employmentId,
            ]);
        }

        $originalEmployee = PayrollRunEmployee::query()
            ->where('run_id', $originalRunId)
            ->where('employment_id', $employmentId)
            ->firstOrFail();

        $baseline = [
            'gross' => (int) $originalPayslip->gross_cents,
            'taxable' => (int) $originalEmployee->taxable_cents,
            'employee_si' => (int) $originalEmployee->employee_si_cents,
            'employee_hf' => (int) $originalEmployee->employee_hf_cents,
            'tax' => (int) $originalEmployee->tax_cents,
            'deductions' => (int) $originalPayslip->deductions_cents,
            'employer_si' => (int) $originalEmployee->employer_si_cents,
            'employer_hf' => (int) $originalEmployee->employer_hf_cents,
            'employer_cost' => (int) $originalPayslip->employer_cost_cents,
            'net' => (int) $originalPayslip->net_cents,
            'ytd_taxable' => (int) $originalEmployee->ytd_taxable_opening_cents,
            'ytd_withheld' => (int) $originalEmployee->ytd_tax_withheld_opening_cents,
            'lines' => $this->linesByKey((int) $originalPayslip->id),
        ];

        // Target-delta semantics: the approved baseline is the original plus
        // every prior effective correction, never the original alone.
        $priorCorrections = PayrollRun::query()
            ->where('correction_of_run_id', $originalRunId)
            ->whereIn('status', ['PUBLISHED', 'PAID', 'CLOSED'])
            ->orderBy('id')
            ->get();

        foreach ($priorCorrections as $prior) {
            $priorPayslip = Payslip::query()
                ->where('run_id', $prior->id)
                ->where('employment_id', $employmentId)
                ->first();
            $priorEmployee = PayrollRunEmployee::query()
                ->where('run_id', $prior->id)
                ->where('employment_id', $employmentId)
                ->first();

            if ($priorPayslip === null || $priorEmployee === null) {
                continue;
            }

            foreach ([
                'gross' => (int) $priorPayslip->gross_cents,
                'deductions' => (int) $priorPayslip->deductions_cents,
                'net' => (int) $priorPayslip->net_cents,
                'employer_cost' => (int) $priorPayslip->employer_cost_cents,
                'taxable' => (int) $priorEmployee->taxable_cents,
                'employee_si' => (int) $priorEmployee->employee_si_cents,
                'employee_hf' => (int) $priorEmployee->employee_hf_cents,
                'tax' => (int) $priorEmployee->tax_cents,
                'employer_si' => (int) $priorEmployee->employer_si_cents,
                'employer_hf' => (int) $priorEmployee->employer_hf_cents,
            ] as $field => $amount) {
                $baseline[$field] += $amount;
            }

            foreach ($this->linesByKey((int) $priorPayslip->id) as $key => $line) {
                if (! isset($baseline['lines'][$key])) {
                    $baseline['lines'][$key] = $line;
                } else {
                    $baseline['lines'][$key]['amount'] += $line['amount'];
                }
            }
        }

        return $baseline;
    }

    /**
     * @return array<string, array{category: string, amount: int}>
     */
    private function linesByKey(int $payslipId): array
    {
        $lines = [];

        foreach (PayslipLine::query()->where('payslip_id', $payslipId)->get() as $line) {
            $key = implode('|', [$line->category, $line->item_code, $line->source_type, $line->source_id]);
            $lines[$key] ??= ['category' => (string) $line->category, 'amount' => 0];
            $lines[$key]['amount'] += (int) $line->amount_cents;
        }

        return $lines;
    }

    /**
     * @param  list<\App\Modules\Payroll\Domain\PayslipLineDraft>  $drafts
     * @return list<PayslipLine>
     */
    private function replaceLines(Payslip $payslip, array $drafts): array
    {
        PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->get()
            ->each(static function (PayslipLine $line): void {
                $line->delete();
            });

        $created = [];

        foreach ($drafts as $draft) {
            $created[] = PayslipLine::query()->create([
                'payslip_id' => $payslip->id,
                'item_code' => $draft->itemCode,
                'category' => $draft->category->value,
                'quantity_scaled' => $draft->quantityScaled,
                'unit' => $draft->unit,
                'rate_scaled' => $draft->rateScaled,
                'amount_cents' => $draft->amount->minor(),
                'taxable' => $draft->taxable,
                'source_type' => $draft->sourceType,
                'source_id' => $draft->sourceId,
                'sort_order' => $draft->sortOrder,
                'created_at' => $this->clock->now(),
            ]);
        }

        return $created;
    }

    /**
     * @param  array<string, array{category: string, amount: int}>  $baselineLines
     * @return list<PayslipLine>
     */
    private function replaceDeltaLines(Payslip $payslip, PayrollCalculationResult $revised, array $baselineLines): array
    {
        $revisedLines = [];

        foreach ($revised->lines as $draft) {
            $key = implode('|', [$draft->category->value, $draft->itemCode, $draft->sourceType, $draft->sourceId]);
            $revisedLines[$key] ??= [
                'category' => $draft->category->value,
                'item_code' => $draft->itemCode,
                'quantity_scaled' => $draft->quantityScaled,
                'unit' => $draft->unit,
                'rate_scaled' => $draft->rateScaled,
                'taxable' => $draft->taxable,
                'source_type' => $draft->sourceType,
                'source_id' => $draft->sourceId,
                'sort_order' => $draft->sortOrder,
                'amount' => 0,
            ];
            $revisedLines[$key]['amount'] += $draft->amount->minor();
        }

        PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->get()
            ->each(static function (PayslipLine $line): void {
                $line->delete();
            });

        $keys = array_values(array_unique([...array_keys($revisedLines), ...array_keys($baselineLines)]));
        sort($keys);
        $created = [];
        $sort = 10;

        foreach ($keys as $key) {
            $revisedLine = $revisedLines[$key] ?? null;
            $baselineAmount = (int) ($baselineLines[$key]['amount'] ?? 0);
            $revisedAmount = (int) ($revisedLine['amount'] ?? 0);
            $delta = $revisedAmount - $baselineAmount;

            if ($delta === 0) {
                continue;
            }

            $meta = $revisedLine ?? ['category' => (string) $baselineLines[$key]['category']];
            $created[] = PayslipLine::query()->create([
                'payslip_id' => $payslip->id,
                'item_code' => (string) ($meta['item_code'] ?? 'CORRECTION_DELTA'),
                'category' => (string) $meta['category'],
                'quantity_scaled' => (int) ($meta['quantity_scaled'] ?? 0),
                'unit' => (string) ($meta['unit'] ?? 'MONTH'),
                'rate_scaled' => (int) ($meta['rate_scaled'] ?? 0),
                'amount_cents' => $delta,
                'taxable' => (bool) ($meta['taxable'] ?? true),
                'source_type' => (string) ($meta['source_type'] ?? 'correction_delta'),
                'source_id' => (string) ($meta['source_id'] ?? (string) $payslip->run_id),
                'sort_order' => $sort,
                'created_at' => $this->clock->now(),
            ]);
            $sort += 10;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blockers
     */
    private function storeBlocked(PayrollRun $run, PayrollInputSnapshot $snapshot, array $blockers): void
    {
        $reason = implode('; ', array_map(
            static fn (array $blocker): string => (string) ($blocker['code'] ?? 'PAYROLL_INPUT_BLOCKED')
                .': '.(string) ($blocker['message'] ?? 'required payroll input is missing'),
            $blockers,
        ));

        PayrollRunEmployee::query()->updateOrCreate(
            ['run_id' => $run->id, 'employment_id' => $snapshot->employment_id],
            [
                'status' => PayrollEmployeeStatus::BLOCKED,
                'gross_cents' => 0,
                'taxable_cents' => 0,
                'deductions_cents' => 0,
                'net_cents' => 0,
                'employer_cost_cents' => 0,
                'employee_si_cents' => 0,
                'employee_hf_cents' => 0,
                'employer_si_cents' => 0,
                'employer_hf_cents' => 0,
                'tax_cents' => 0,
                'ytd_applied' => false,
                'failure_reason' => mb_substr($reason, 0, 1000),
            ],
        );
    }

    /**
     * Resolve one scheme item to integer cents. The item calculation column is
     * the execution contract; amount_cents is not a fallback for a malformed
     * PERCENT/FORMULA item.
     *
     * @param  array<string, mixed>  $item
     */
    private function schemeItemAmount(array $item, int $baseSalaryCents, AttendanceSummary $attendance): int
    {
        $calculation = strtoupper((string) ($item['calculation'] ?? 'FIXED'));

        if ($calculation === 'FIXED') {
            return max(0, (int) ($item['amount_cents'] ?? 0));
        }

        if ($calculation === 'PERCENT') {
            $rateScaled = $item['rate_scaled'] ?? null;
            if (! is_int($rateScaled) || $rateScaled < 0) {
                throw new \InvalidArgumentException('PERCENT scheme item requires a non-negative rate_scaled.');
            }

            return Decimal::fromScaledInt($baseSalaryCents, 0)
                ->multiply(Decimal::fromScaledInt($rateScaled, 6))
                ->roundTo(0)
                ->toScaledInt(0);
        }

        if ($calculation === 'ATTENDANCE_PRORATE') {
            $amount = (int) ($item['amount_cents'] ?? 0);
            if ($attendance->scheduledMinutes <= 0) {
                return max(0, $amount);
            }

            return Decimal::fromScaledInt($amount, 0)
                ->multiply(Decimal::fromScaledInt($attendance->paidMinutes(), 0))
                ->divide(Decimal::fromScaledInt($attendance->scheduledMinutes, 0), 0)
                ->toScaledInt(0);
        }

        if ($calculation === 'FORMULA_LIMITED') {
            $formula = is_array($item['rule_ast'] ?? null) ? $item['rule_ast'] : ($item['formula_json'] ?? null);
            if (! is_array($formula)) {
                throw new \InvalidArgumentException('FORMULA_LIMITED scheme item requires formula_json or a published rule AST.');
            }
            if (isset($item['rule_unit']) && $item['rule_unit'] !== 'CENT') {
                throw new \InvalidArgumentException('Published payroll rule unit must be CENT.');
            }
            if (isset($item['rule_hash']) && is_string($item['rule_hash'])) {
                $encoded = json_encode($formula, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (! hash_equals($item['rule_hash'], hash('sha256', $encoded))) {
                    throw new \InvalidArgumentException('Published payroll rule hash does not match the frozen AST.');
                }
            }

            $compiled = $this->ruleCompiler->compile($formula, [
                'base_salary', 'base_salary_cents', 'scheduled_minutes', 'worked_minutes',
                'paid_leave_minutes', 'unpaid_leave_minutes', 'overtime_minutes',
            ]);
            $evaluation = $this->ruleEngine->evaluate($compiled, [
                'base_salary' => Decimal::fromScaledInt($baseSalaryCents, 2)->toDecimalString(),
                'base_salary_cents' => (string) $baseSalaryCents,
                'scheduled_minutes' => (string) $attendance->scheduledMinutes,
                'worked_minutes' => (string) $attendance->workedMinutes,
                'paid_leave_minutes' => (string) $attendance->paidLeaveMinutes,
                'unpaid_leave_minutes' => (string) $attendance->unpaidLeaveMinutes,
                'overtime_minutes' => (string) $attendance->overtimeMinutes,
            ]);
            $cents = Decimal::fromString($evaluation->value)->roundTo(0)->toScaledInt(0);
            if ($cents < 0) {
                throw new \InvalidArgumentException('Scheme item formula produced a negative amount.');
            }

            return $cents;
        }

        throw new \InvalidArgumentException('Unknown scheme item calculation '.$calculation.'.');
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function weightedSegmentSalary(array $segments, string $periodStart, string $periodEnd): ?int
    {
        $totalDays = $this->weekdayCount($periodStart, $periodEnd);

        if ($totalDays <= 0) {
            return null;
        }

        $weighted = 0;

        foreach ($segments as $segment) {
            $from = max($periodStart, (string) ($segment['segment_from'] ?? ''));
            $to = min($periodEnd, (string) ($segment['segment_to'] ?? ''));
            $days = $this->weekdayCount($from, $to);

            if ($days <= 0) {
                continue;
            }

            $weighted += intdiv(
                (int) ($segment['base_salary_cents'] ?? 0) * $days + intdiv($totalDays, 2),
                $totalDays,
            );
        }

        return $weighted > 0 ? $weighted : null;
    }

    private function weekdayCount(string $from, string $to): int
    {
        if ($from === '' || $to === '' || $from > $to) {
            return 0;
        }

        $cursor = \Carbon\CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = \Carbon\CarbonImmutable::parse($to, 'UTC')->startOfDay();
        $days = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($cursor->dayOfWeekIso <= 5) {
                $days++;
            }

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private function storeFailure(PayrollRun $run, PayrollInputSnapshot $snapshot, string $reason): void
    {
        PayrollRunEmployee::query()->updateOrCreate(
            ['run_id' => $run->id, 'employment_id' => $snapshot->employment_id],
            [
                'status' => PayrollEmployeeStatus::FAILED,
                'gross_cents' => 0,
                'taxable_cents' => 0,
                'deductions_cents' => 0,
                'net_cents' => 0,
                'employer_cost_cents' => 0,
                'employee_si_cents' => 0,
                'employee_hf_cents' => 0,
                'employer_si_cents' => 0,
                'employer_hf_cents' => 0,
                'tax_cents' => 0,
                'ytd_applied' => false,
                'failure_reason' => mb_substr($reason, 0, 1000),
            ],
        );
    }

    private function checkedAdd(int $left, int $right): int
    {
        $sum = bcadd((string) $left, (string) $right, 0);

        if (bccomp($sum, (string) PHP_INT_MAX, 0) > 0 || bccomp($sum, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException('Payroll total exceeds native integer range.');
        }

        return (int) $sum;
    }
}

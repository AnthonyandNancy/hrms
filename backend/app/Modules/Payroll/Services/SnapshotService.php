<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Payroll\Contracts\BenefitPayrollInputDirectory;
use App\Modules\Payroll\Contracts\CompensationVersionDirectory;
use App\Modules\Payroll\Contracts\PerformanceBonusDirectory;
use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\PayrollYtd;
use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Locks and freezes every input needed to reproduce a payroll calculation.
 * Inputs are normalized to integers / decimal strings only; the source hash is
 * stable across JSON key ordering and idempotent runs.
 */
final class SnapshotService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PayrollInputDirectory $time,
        private readonly EmploymentTermsDirectory $terms,
        private readonly PeopleDirectory $people,
        private readonly CompanyProfileReader $company,
        private readonly TaxParameterResolver $parameters,
        private readonly CompensationVersionDirectory $compensations,
        private readonly BenefitPayrollInputDirectory $benefits,
        private readonly PerformanceBonusDirectory $performanceBonuses,
    ) {}

    /**
     * Build snapshots for every employment in the run and set the run hash.
     *
     * @param  null|callable(array<string, mixed>): bool  $eligible
     */
    public function lock(PayrollRun $run, int $actorUserId, ?callable $eligible = null): string
    {
        return DB::transaction(function () use ($run, $eligible): string {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $status = $locked->status instanceof PayrollRunStatus
                ? $locked->status
                : PayrollRunStatus::from((string) $locked->status);

            // Re-locking an already locked run is idempotent only when no
            // snapshot exists. A reopened run increments the snapshot version
            // and is handled by ReopenService.
            if ($status !== PayrollRunStatus::DRAFT && $status !== PayrollRunStatus::INPUT_LOCKED) {
                throw new ConflictException('Inputs can only be locked before calculation.', [
                    'reason' => 'RUN_NOT_LOCKABLE',
                    'status' => $status->value,
                ]);
            }

            $scheme = PayrollScheme::query()
                ->whereKey($locked->scheme_id)
                ->firstOrFail();
            $company = $this->company->current();

            $version = max(1, (int) $locked->snapshot_version);
            $hashes = [];
            $employments = $this->employmentIds($locked, $eligible);

            if ($employments === []) {
                throw new ConflictException('No active employment is in scope for this payroll run.', [
                    'reason' => 'EMPTY_PAYROLL_POPULATION',
                ]);
            }

            foreach ($employments as $employmentId) {
                $payload = $this->buildPayload($locked, $scheme, $employmentId, $company->currencyCode);
                $hash = $this->hashPayload($payload);
                $hashes[$employmentId] = $hash;

                PayrollInputSnapshot::query()->firstOrCreate(
                    [
                        'run_id' => $locked->id,
                        'employment_id' => $employmentId,
                        'snapshot_type' => 'INPUT',
                        'snapshot_version' => $version,
                    ],
                    [
                        'payload_json' => $payload,
                        'source_hash' => $hash,
                        'created_at' => $this->clock->now(),
                    ],
                );

                // Linking pre-lock adjustments is a one-time claim. Reopen
                // creates a new version and must not re-stamp old evidence.
                PayrollAdjustment::query()
                    ->whereNull('run_id')
                    ->where('employment_id', $employmentId)
                    ->where('effective_period', (string) $locked->period)
                    ->update(['run_id' => $locked->id]);
            }

            ksort($hashes, SORT_NUMERIC);
            $sourceHash = hash('sha256', $this->canonicalJson([
                'run_period' => (string) $locked->period,
                'scheme_id' => (int) $locked->scheme_id,
                'scheme_version' => (int) $scheme->version,
                'rule_version' => (string) $locked->rule_version,
                'employees' => $hashes,
            ]));

            $locked->forceFill([
                'input_hash' => $sourceHash,
                'snapshot_version' => $version,
            ])->save();

            return $sourceHash;
        });
    }

    /**
     * Every employment active in the earning period for the company.
     *
     * @return list<int>
     */
    private function employmentIds(PayrollRun $run, ?callable $eligible): array
    {
        $from = $this->dateOnly($run->earning_period_start);
        $to = $this->dateOnly($run->earning_period_end);
        $ids = $this->people->employmentIdsAsOf($to);

        // A mid-month join/leave is relevant when any day intersects the
        // period even if the final day is after termination.
        foreach ($this->people->employmentIdsAsOf($from) as $id) {
            $ids[] = $id;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($eligible !== null) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => (bool) $eligible([
                'employment_id' => $id,
                'from' => $from,
                'to' => $to,
            ])));
        }

        return $ids;
    }

    /**
     * Correction runs reuse the original employment population; they do not
     * infer a new population from current assignments.
     */
    public function lockForCorrection(PayrollRun $run, int $actorUserId): string
    {
        $original = PayrollRun::query()
            ->whereKey((int) $run->correction_of_run_id)
            ->firstOrFail();

        $ids = \App\Modules\Payroll\Models\PayrollRunEmployee::query()
            ->where('run_id', $original->id)
            ->orderBy('employment_id')
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $this->lockForEmploymentIds($run, $actorUserId, $ids);
    }

    /**
     * @param  list<int>  $employmentIds
     */
    public function lockForEmploymentIds(PayrollRun $run, int $actorUserId, array $employmentIds): string
    {
        return DB::transaction(function () use ($run, $employmentIds): string {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $scheme = PayrollScheme::query()
                ->whereKey($locked->scheme_id)
                ->firstOrFail();
            $company = $this->company->current();

            $version = max(1, (int) $locked->snapshot_version);
            $hashes = [];

            foreach ($employmentIds as $employmentId) {
                $payload = $this->buildPayload($locked, $scheme, $employmentId, $company->currencyCode);
                $hash = $this->hashPayload($payload);
                $hashes[$employmentId] = $hash;

                PayrollInputSnapshot::query()->firstOrCreate(
                    [
                        'run_id' => $locked->id,
                        'employment_id' => $employmentId,
                        'snapshot_type' => 'INPUT',
                        'snapshot_version' => $version,
                    ],
                    [
                        'payload_json' => $payload,
                        'source_hash' => $hash,
                        'created_at' => $this->clock->now(),
                    ],
                );
            }

            ksort($hashes, SORT_NUMERIC);
            $sourceHash = hash('sha256', $this->canonicalJson([
                'run_period' => (string) $locked->period,
                'scheme_id' => (int) $locked->scheme_id,
                'scheme_version' => (int) $scheme->version,
                'rule_version' => (string) $locked->rule_version,
                'employees' => $hashes,
                'correction_of_run_id' => $locked->correction_of_run_id !== null ? (int) $locked->correction_of_run_id : null,
            ]));

            $locked->forceFill([
                'input_hash' => $sourceHash,
                'snapshot_version' => $version,
            ])->save();

            return $sourceHash;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(PayrollRun $run, PayrollScheme $scheme, int $employmentId, string $currency): array
    {
        $closedFact = $this->time->closedFactForPeriod($employmentId, (string) $run->period);
        $attendance = null;
        $blockers = [];
        $asOf = $this->dateOnly($run->earning_period_end);
        $compensation = $this->compensations->effectiveForEmployment($employmentId, $asOf);
        $compensationSegments = $this->compensations->segmentsForPeriod(
            $employmentId,
            $this->dateOnly($run->earning_period_start),
            $this->dateOnly($run->earning_period_end),
        );
        $terms = $this->terms->termsAsOf($employmentId, $asOf);
        $blockers = array_merge($blockers, $this->compensationSegmentBlockers(
            $compensationSegments,
            $this->dateOnly($run->earning_period_start),
            $this->dateOnly($run->earning_period_end),
            $terms === null ? null : $terms->effectiveFrom,
            $terms === null ? null : $terms->effectiveTo,
            $employmentId,
            (string) $run->period,
        ));
        $resolvedScheme = $scheme;

        if ($compensation !== null && $compensation['payroll_scheme_id'] !== null) {
            $candidate = PayrollScheme::query()
                ->whereKey((int) $compensation['payroll_scheme_id'])
                ->with('items')
                ->first();

            if ($candidate instanceof PayrollScheme) {
                $resolvedScheme = $candidate;
            }
        }
        $adjustments = PayrollAdjustment::query()
            ->where(function ($query) use ($run): void {
                $query->where('run_id', $run->id)->orWhere(function ($inner) use ($run): void {
                    $inner->whereNull('run_id')->where('effective_period', (string) $run->period);
                });
            })
            ->where('employment_id', $employmentId)
            ->orderBy('id')
            ->get();

        $year = (int) substr((string) $run->period, 0, 4);
        $ytd = PayrollYtd::query()
            ->where('employment_id', $employmentId)
            ->where('tax_year', $year)
            ->first();

        $taxVersion = $this->parameters->versionFor('TAX', (string) $run->cutoff_at);
        $siVersion = $this->parameters->versionFor('SOCIAL_INSURANCE', (string) $run->cutoff_at);
        $hfVersion = $this->parameters->versionFor('HOUSING_FUND', (string) $run->cutoff_at);

        if ($closedFact !== null) {
            $attendanceSummary = [
                'from' => $closedFact->from,
                'to' => $closedFact->to,
                'scheduled_minutes' => $closedFact->scheduledMinutes,
                'worked_minutes' => $closedFact->workedMinutes,
                'paid_leave_minutes' => $closedFact->paidLeaveMinutes,
                'partial_leave_minutes' => $closedFact->partialLeaveMinutes,
                'unpaid_leave_minutes' => $closedFact->unpaidLeaveMinutes,
                'overtime_minutes' => $closedFact->overtimePayMinutes,
                'overtime_timeoff_minutes' => $closedFact->overtimeTimeoffMinutes,
                'travel_minutes' => $closedFact->travelMinutes,
                'source_type' => 'time_closed_fact',
                'fact_version' => $closedFact->factVersion,
                'fact_version_id' => $closedFact->factVersionId,
                'source_hash' => $closedFact->sourceHash,
            ];
        } else {
            // No Time module data is a legitimate full-month salary case. Once
            // Time has evidence for the period, the absence of a closed fact is
            // a payroll blocker; live attendance may be shown only as evidence,
            // never treated as frozen payroll input.
            $attendance = $this->time->attendanceForPeriod($employmentId, (string) $run->period);
            $hasTimeEvidence = $this->time->hasRecordedAttendance($employmentId, (string) $run->period);

            if ($hasTimeEvidence) {
                $blockers[] = [
                    'code' => 'CLOSED_TIME_FACT_MISSING',
                    'message' => 'The payroll period has Time evidence but no closed Time fact version.',
                    'employment_id' => $employmentId,
                    'period' => (string) $run->period,
                ];
            }

            $attendanceSummary = $attendance === null
                ? ['scheduled_minutes' => 0, 'worked_minutes' => 0, 'paid_leave_minutes' => 0, 'unpaid_leave_minutes' => 0, 'overtime_minutes' => 0, 'source_type' => 'full_month', 'source_hash' => hash('sha256', 'FULL-MONTH|'.$employmentId.'|'.$run->period)]
                : [
                    'from' => $attendance->from,
                    'to' => $attendance->to,
                    'scheduled_minutes' => $attendance->scheduledMinutes,
                    'worked_minutes' => $attendance->workedMinutes,
                    'paid_leave_minutes' => $attendance->paidLeaveMinutes,
                    'unpaid_leave_minutes' => $attendance->unpaidLeaveMinutes,
                    'overtime_minutes' => $attendance->overtimeMinutes,
                    'leave_consumption' => $attendance->leaveConsumption,
                    'source_type' => $hasTimeEvidence ? 'live_fallback' : 'full_month',
                    'source_hash' => $attendance->sourceHash,
                ];
        }

        $payload = [
            'schema_version' => 2,
            'currency' => $compensation['currency'] ?? $currency,
            'employment_id' => $employmentId,
            'period' => (string) $run->period,
            'earning_period_start' => $this->dateOnly($run->earning_period_start),
            'earning_period_end' => $this->dateOnly($run->earning_period_end),
            'posting_period' => (string) $run->posting_period,
            'pay_date' => $this->dateOnly($run->pay_date),
            'cutoff_at' => (string) $run->cutoff_at,
            'attendance' => $attendanceSummary,
            'blockers' => $blockers,
            'compensation' => $compensation,
            'compensation_segments' => $compensationSegments,
            'benefits' => $this->benefits->frozenForPeriod($employmentId, (string) $run->period),
            'performance_bonuses' => $this->performanceBonuses->frozenForPeriod($employmentId, (string) $run->period),
            'terms' => $terms === null ? null : [
                'terms_id' => $terms->id,
                'version' => $terms->version,
                'effective_from' => $terms->effectiveFrom,
                'effective_to' => $terms->effectiveTo,
                'base_salary_cents' => $terms->baseSalaryMinor,
                'salary_currency' => $terms->salaryCurrency,
                'salary_scheme_code' => $terms->salarySchemeCode,
            ],
            'scheme' => [
                'scheme_id' => (int) $resolvedScheme->id,
                'version' => (int) $resolvedScheme->version,
                'code' => (string) $resolvedScheme->code,
                'currency' => (string) $resolvedScheme->currency,
                'proration_divisor' => (string) $resolvedScheme->proration_divisor,
                'rounding_mode' => (string) $resolvedScheme->rounding_mode,
                'overtime_multiplier' => (string) $resolvedScheme->overtime_multiplier,
                'allow_negative_net' => (bool) $resolvedScheme->allow_negative_net,
                'base_salary_cents' => (int) $resolvedScheme->base_salary_cents,
                'items' => $resolvedScheme->items->map(static fn ($item): array => [
                    'item_code' => (string) $item->item_code,
                    'label' => (string) $item->label,
                    'category' => (string) $item->category,
                    'calculation' => (string) $item->calculation,
                    'amount_cents' => (int) $item->amount_cents,
                    'rate_scaled' => $item->rate_scaled !== null ? (int) $item->rate_scaled : null,
                    'basis' => (string) $item->basis,
                    'sort_order' => (int) $item->sort_order,
                    'taxable' => (bool) $item->taxable,
                ])->values()->all(),
            ],
            'manual_adjustments' => $adjustments->map(static fn (PayrollAdjustment $adjustment): array => [
                'adjustment_id' => (int) $adjustment->id,
                'item_code' => (string) $adjustment->item_code,
                'amount_cents' => (int) $adjustment->amount_cents,
                'reason' => (string) $adjustment->reason,
                'case_id' => $adjustment->case_id !== null ? (int) $adjustment->case_id : null,
                'approved_by' => (int) $adjustment->approved_by,
                'effective_period' => (string) $adjustment->effective_period,
            ])->values()->all(),
            'ytd_opening' => [
                'tax_year' => $year,
                'taxable_income_cents' => $ytd !== null ? (int) $ytd->taxable_income_cents : 0,
                'tax_withheld_cents' => $ytd !== null ? (int) $ytd->tax_withheld_cents : 0,
                'social_insurance_cents' => $ytd !== null ? (int) $ytd->social_insurance_cents : 0,
                'housing_fund_cents' => $ytd !== null ? (int) $ytd->housing_fund_cents : 0,
                'gross_cents' => $ytd !== null ? (int) $ytd->gross_cents : 0,
                'net_cents' => $ytd !== null ? (int) $ytd->net_cents : 0,
            ],
            'tax_parameters_version' => $taxVersion,
            'social_insurance_parameters_version' => $siVersion,
            'housing_fund_parameters_version' => $hfVersion,
        ];

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function compensationSegmentBlockers(
        array $segments,
        string $periodStart,
        string $periodEnd,
        ?string $termsFrom,
        ?string $termsTo,
        int $employmentId,
        string $period,
    ): array {
        if ($segments === []) {
            return [];
        }

        $expectedStart = $periodStart;
        if ($termsFrom !== null && $termsFrom > $expectedStart && $termsFrom <= $periodEnd) {
            $expectedStart = $termsFrom;
        }

        $expectedEnd = $periodEnd;
        if ($termsTo !== null && $termsTo < $expectedEnd) {
            $expectedEnd = $termsTo;
        }

        $overlap = false;
        $previousTo = null;

        foreach ($segments as $segment) {
            $from = (string) ($segment['segment_from'] ?? '');
            $to = (string) ($segment['segment_to'] ?? '');

            if ($previousTo !== null && $from <= $previousTo) {
                $overlap = true;
            }

            $previousTo = $to;
        }

        if ($overlap) {
            return [[
                'code' => 'COMPENSATION_SEGMENT_OVERLAP',
                'message' => 'Execution compensation segments overlap; payroll cannot choose a single rate.',
                'employment_id' => $employmentId,
                'period' => $period,
            ]];
        }

        $firstFrom = (string) ($segments[0]['segment_from'] ?? '');
        $lastTo = (string) ($segments[count($segments) - 1]['segment_to'] ?? '');

        if ($firstFrom > $expectedStart || $lastTo < $expectedEnd) {
            return [[
                'code' => 'COMPENSATION_SEGMENT_GAP',
                'message' => 'Execution compensation segments do not cover the whole payable period.',
                'employment_id' => $employmentId,
                'period' => $period,
                'expected_from' => $expectedStart,
                'expected_to' => $expectedEnd,
            ]];
        }

        return [];
    }

    private function dateOnly(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * Stable hash over a recursively key-sorted value.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function canonicalJson(array $value): string
    {
        $normalized = $this->sortRecursively($value);

        return (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortRecursively(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->sortRecursively($child);
            }
        }

        return $value;
    }

    /**
     * @return array{employment_id: int, scheduled_minutes: int, worked_minutes: int, paid_leave_minutes: int, unpaid_leave_minutes: int, overtime_minutes: int}
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array{employment_id: int, scheduled_minutes: int, worked_minutes: int, paid_leave_minutes: int, unpaid_leave_minutes: int, overtime_minutes: int}
     */
    public function attendanceValues(array $payload): array
    {
        $attendance = (array) ($payload['attendance'] ?? []);

        return [
            'employment_id' => (int) ($payload['employment_id'] ?? 0),
            'scheduled_minutes' => (int) ($attendance['scheduled_minutes'] ?? 0),
            'worked_minutes' => (int) ($attendance['worked_minutes'] ?? 0),
            'paid_leave_minutes' => (int) ($attendance['paid_leave_minutes'] ?? 0),
            'unpaid_leave_minutes' => (int) ($attendance['unpaid_leave_minutes'] ?? 0),
            'overtime_minutes' => (int) ($attendance['overtime_minutes'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function attendanceSummary(array $payload): AttendanceSummary
    {
        $values = $this->attendanceValues($payload);

        return new AttendanceSummary(
            scheduledMinutes: $values['scheduled_minutes'],
            workedMinutes: $values['worked_minutes'],
            paidLeaveMinutes: $values['paid_leave_minutes'],
            unpaidLeaveMinutes: $values['unpaid_leave_minutes'],
            overtimeMinutes: $values['overtime_minutes'],
        );
    }
}

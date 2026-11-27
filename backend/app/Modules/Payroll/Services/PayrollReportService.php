<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregate-only payroll reporting over finalized runs.
 *
 * Only PUBLISHED, PAID and CLOSED runs are considered. The query never selects
 * payroll_run_employees identifiers into the response; department grouping uses
 * the People directory as-of the run cutoff and exposes only a department id and
 * name. All monetary totals remain integer cents.
 */
final class PayrollReportService
{
    private const FINAL_STATUSES = ['PUBLISHED', 'PAID', 'CLOSED'];

    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly OrganizationFacts $organization,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @param  array{period?: string|null, from?: string|null, to?: string|null, group_by?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $groupBy = (string) ($filters['group_by'] ?? 'period');

        if (! in_array($groupBy, ['period', 'department'], true)) {
            throw new ConflictException('The report grouping is not supported.', [
                'reason' => 'PAYROLL_REPORT_GROUP_UNSUPPORTED',
            ]);
        }

        $runs = $this->finalizedRuns($filters)
            ->with(['employees' => static function ($query): void {
                $query->where('status', 'CALCULATED')->orderBy('employment_id');
            }])
            ->orderBy('period')
            ->orderBy('id')
            ->get();

        $currencies = $runs
            ->map(fn (PayrollRun $run): string => $this->currency($run))
            ->unique()
            ->values()
            ->all();

        if (count($currencies) > 1) {
            throw new ConflictException('Payroll report rows span multiple currencies.', [
                'reason' => 'PAYROLL_REPORT_MIXED_CURRENCY',
                'currencies' => $currencies,
            ]);
        }

        $rows = $groupBy === 'department'
            ? $this->departmentRows($runs)
            : $this->periodRows($runs);

        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) $row[$key];
            }
        }

        return [
            'as_of' => $this->businessDates->today()->value(),
            'source' => 'PUBLISHED_PAYROLL_RUNS',
            'group_by' => $groupBy,
            'currency' => $currencies[0] ?? 'CNY',
            'items' => $rows,
            'totals' => $totals,
            // A per-period series is always returned so the UI can render a
            // trend without a second aggregate query.
            'trend' => $this->periodRows($runs),
        ];
    }

    /**
     * @param  array{period?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<PayrollRun>
     */
    private function finalizedRuns(array $filters): Builder
    {
        return PayrollRun::query()
            ->whereIn('status', self::FINAL_STATUSES)
            ->when(isset($filters['period']), function (Builder $query) use ($filters): void {
                $query->where('period', (string) $filters['period']);
            })
            ->when(isset($filters['from']), function (Builder $query) use ($filters): void {
                $query->where('period', '>=', substr((string) $filters['from'], 0, 7));
            })
            ->when(isset($filters['to']), function (Builder $query) use ($filters): void {
                $query->where('period', '<=', substr((string) $filters['to'], 0, 7));
            });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PayrollRun>  $runs
     * @return list<array<string, mixed>>
     */
    private function periodRows($runs): array
    {
        $rows = [];

        foreach ($runs as $run) {
            $period = (string) $run->period;

            if (! isset($rows[$period])) {
                $rows[$period] = [
                    'period' => $period,
                    'department_id' => null,
                    'department_name' => null,
                    ...$this->emptyRow(),
                ];
            }

            $rows[$period]['run_count'] = (int) $rows[$period]['run_count'] + 1;
            $rows[$period]['employee_count'] = (int) $rows[$period]['employee_count'] + $run->employees->count();
            $rows[$period]['gross_cents'] = (int) $rows[$period]['gross_cents'] + (int) $run->gross_cents;
            $rows[$period]['deductions_cents'] = (int) $rows[$period]['deductions_cents'] + (int) $run->deductions_cents;
            $rows[$period]['net_cents'] = (int) $rows[$period]['net_cents'] + (int) $run->net_cents;
            $rows[$period]['employer_cost_cents'] = (int) $rows[$period]['employer_cost_cents'] + (int) $run->employer_cost_cents;
        }

        return array_values($rows);
    }

    /**
     * A department can contain several employees in the same run, so
     * run_count counts distinct finalized runs, never employee rows.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, PayrollRun>  $runs
     * @return list<array<string, mixed>>
     */
    private function departmentRows($runs): array
    {
        $rows = [];
        $runsByDepartment = [];

        foreach ($runs as $run) {
            /** @var PayrollRunEmployee $employee */
            foreach ($run->employees as $employee) {
                $snapshot = $this->people->employmentSnapshot(
                    (int) $employee->employment_id,
                    $this->dateOnly($run->cutoff_at),
                );
                $orgUnitId = $snapshot?->orgUnitId;
                $key = $orgUnitId === null ? 'none' : (string) $orgUnitId;

                if (! isset($rows[$key])) {
                    $orgUnit = $orgUnitId !== null ? $this->organization->orgUnit($orgUnitId) : null;
                    $rows[$key] = [
                        'period' => null,
                        'department_id' => $orgUnitId,
                        'department_name' => $orgUnit !== null ? $orgUnit->name : '未分配部门',
                        ...$this->emptyRow(),
                    ];
                }

                $runsByDepartment[$key][(int) $run->id] = true;
                $rows[$key]['employee_count'] = (int) $rows[$key]['employee_count'] + 1;
                $rows[$key]['gross_cents'] = (int) $rows[$key]['gross_cents'] + (int) $employee->gross_cents;
                $rows[$key]['deductions_cents'] = (int) $rows[$key]['deductions_cents'] + (int) $employee->deductions_cents;
                $rows[$key]['net_cents'] = (int) $rows[$key]['net_cents'] + (int) $employee->net_cents;
                $rows[$key]['employer_cost_cents'] = (int) $rows[$key]['employer_cost_cents'] + (int) $employee->employer_cost_cents;
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['run_count'] = count($runsByDepartment[$key] ?? []);
        }

        usort($rows, static fn (array $left, array $right): int => [$left['department_id'] ?? 0, $left['department_name']] <=> [$right['department_id'] ?? 0, $right['department_name']]);

        return $rows;
    }

    /**
     * @return array{run_count: int, employee_count: int, gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int}
     */
    private function emptyRow(): array
    {
        return ['run_count' => 0, 'employee_count' => 0, ...$this->emptyTotals()];
    }

    /**
     * @return array{gross_cents: int, deductions_cents: int, net_cents: int, employer_cost_cents: int}
     */
    private function emptyTotals(): array
    {
        return [
            'gross_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_cost_cents' => 0,
        ];
    }

    private function currency(PayrollRun $run): string
    {
        $scheme = \App\Modules\Payroll\Models\PayrollScheme::query()
            ->whereKey((int) $run->scheme_id)
            ->first();

        return $scheme !== null ? (string) $scheme->currency : 'CNY';
    }

    private function dateOnly(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollEmployeeStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Shared\Exceptions\ValidationException;

/**
 * Compares a vetted internal export with an old-system export. Differences are
 * reported per employee in integer cents; absent old rows are reported, never
 * silently ignored.
 */
final class PayrollParallelCheckService
{
    /**
     * @return array{period: string, headcount_new: int, headcount_old: int, headcount_match: bool, gross_old_cents: int, gross_new_cents: int, net_old_cents: int, net_new_cents: int, differences: list<array<string, mixed>>, unexplained_count: int, passed: bool}
     */
    public function compareText(string $period, string $oldCsv): array
    {
        $old = $this->parseOldCsv($oldCsv);

        $runs = PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', [
                PayrollRunStatus::CALCULATED->value,
                PayrollRunStatus::REVIEWED->value,
                PayrollRunStatus::APPROVED->value,
                PayrollRunStatus::PUBLISHED->value,
                PayrollRunStatus::PAID->value,
                PayrollRunStatus::CLOSED->value,
            ])
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            throw new ValidationException(['period' => ['No calculated payroll run exists for this period.']]);
        }

        $newRows = [];
        $failedRows = [];

        foreach ($runs as $run) {
            foreach (PayrollRunEmployee::query()->where('run_id', $run->id)->orderBy('employment_id')->get() as $employee) {
                // A failed employee row is not a zero-value calculation. Folding
                // it into the headcount would let an unresolved payroll error
                // read as a one-cent difference, or hide entirely if the legacy
                // export also has no row. Report it as an explicit blocker.
                if ($employee->status === PayrollEmployeeStatus::FAILED) {
                    $failedRows[] = [
                        'employment_id' => (int) $employee->employment_id,
                        'reason' => 'CALCULATION_FAILED',
                        'detail' => (string) ($employee->failure_reason ?? ''),
                    ];

                    continue;
                }

                $key = (string) $employee->employment_id;
                $newRows[$key] ??= ['gross' => 0, 'net' => 0, 'headcount' => 0];
                $newRows[$key]['gross'] += (int) $employee->gross_cents;
                $newRows[$key]['net'] += (int) $employee->net_cents;
                $newRows[$key]['headcount'] = 1;
            }
        }

        $differences = $failedRows;
        $keys = array_values(array_unique([...array_keys($newRows), ...array_keys($old['rows'])]));
        sort($keys, SORT_NUMERIC);
        $unexplained = 0;

        foreach ($keys as $key) {
            $new = $newRows[$key] ?? ['gross' => 0, 'net' => 0, 'headcount' => 0];
            $oldRow = $old['rows'][$key] ?? ['gross' => 0, 'net' => 0];

            if (! isset($newRows[$key]) || ! isset($old['rows'][$key])) {
                $differences[] = [
                    'employment_id' => (int) $key,
                    'reason' => isset($newRows[$key]) ? 'MISSING_IN_OLD' : 'MISSING_IN_NEW',
                    'new_net_cents' => $new['net'],
                    'old_net_cents' => $oldRow['net'],
                ];
                $unexplained++;

                continue;
            }

            $netDiff = $new['net'] - $oldRow['net'];
            $grossDiff = $new['gross'] - $oldRow['gross'];

            if ($netDiff !== 0 || $grossDiff !== 0) {
                $differences[] = [
                    'employment_id' => (int) $key,
                    'reason' => 'VALUE_DIFF',
                    'new_gross_cents' => $new['gross'],
                    'old_gross_cents' => $oldRow['gross'],
                    'gross_diff_cents' => $grossDiff,
                    'new_net_cents' => $new['net'],
                    'old_net_cents' => $oldRow['net'],
                    'net_diff_cents' => $netDiff,
                ];

                // Parallel-run tolerance is one cent (0.01) on the net amount;
                // any larger unexplained difference fails the check.
                if ($this->beyondTolerance($netDiff) || $this->beyondTolerance($grossDiff)) {
                    $unexplained++;
                }
            }
        }

        $headcountNew = count($newRows);
        $headcountOld = $old['headcount'];

        return [
            'period' => $period,
            'headcount_new' => $headcountNew,
            'headcount_old' => $headcountOld,
            'headcount_match' => $headcountNew === $headcountOld,
            'gross_old_cents' => $old['gross'],
            'gross_new_cents' => array_sum(array_column($newRows, 'gross')),
            'net_old_cents' => $old['net'],
            'net_new_cents' => array_sum(array_column($newRows, 'net')),
            'differences' => $differences,
            'failed_employee_count' => count($failedRows),
            'unexplained_count' => $unexplained + count($failedRows),
            'passed' => $unexplained === 0 && $failedRows === [] && $headcountNew === $headcountOld,
        ];
    }

    /**
     * @return array{rows: array<int, array{gross: int, net: int}>, headcount: int, gross: int, net: int}
     */
    private function parseOldCsv(string $csv): array
    {
        // Strip a UTF-8 BOM and comment lines.
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $rows = [];
        $gross = 0;
        $net = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = str_getcsv($line);

            if (! isset($columns[0]) || ! is_numeric(trim((string) $columns[0]))) {
                continue;
            }

            $employmentId = (int) trim((string) $columns[0]);
            $grossCents = isset($columns[1]) && is_numeric(trim((string) $columns[1])) ? (int) trim((string) $columns[1]) : 0;
            $netCents = isset($columns[2]) && is_numeric(trim((string) $columns[2])) ? (int) trim((string) $columns[2]) : 0;

            $rows[$employmentId] = ['gross' => $grossCents, 'net' => $netCents];
            $gross += $grossCents;
            $net += $netCents;
        }

        if ($rows === []) {
            throw new ValidationException(['old_file' => ['The old export contains no employee rows.']]);
        }

        return ['rows' => $rows, 'headcount' => count($rows), 'gross' => $gross, 'net' => $net];
    }

    private function beyondTolerance(int $differenceCents): bool
    {
        $absolute = $differenceCents < 0 ? -$differenceCents : $differenceCents;

        return $absolute > 1;
    }
}

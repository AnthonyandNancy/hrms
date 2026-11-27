<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * CSV-only adapters. No bank clearing, no tax filing: the payloads are payment
 * instructions and return rows for an external processor.
 */
final class ExportService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{filename: string, content: string, sha256: string}
     */
    public function bankCsv(int $runId, int $actorUserId): array
    {
        return DB::transaction(function () use ($runId, $actorUserId): array {
            $run = $this->run($runId);
            $this->assertExportable($run);
            $payslips = Payslip::query()
                ->where('run_id', $runId)
                ->orderBy('employment_id')
                ->get();

            if ($payslips->isEmpty()) {
                throw new ConflictException('The run has no payslips to export.', ['reason' => 'NO_PAYSLIPS']);
            }

            $rows = [];
            $rows[] = ['employee_id', 'period', 'currency', 'net_cents', 'pay_date', 'reference'];
            foreach ($payslips as $payslip) {
                $rows[] = [
                    (string) $payslip->employment_id,
                    (string) $run->period,
                    (string) $this->currency($run),
                    (string) $payslip->net_cents,
                    substr((string) $run->pay_date, 0, 10),
                    'PAYROLL-'.$run->id.'-'.$payslip->employment_id,
                ];
            }

            $content = $this->csv($rows, '# BANK PAYMENT INSTRUCTION - CSV ADAPTER ONLY; NOT A BANK FILE OR CLEARING INSTRUCTION');
            $hash = hash('sha256', $content);

            $run->forceFill([
                'bank_export_hash' => $hash,
                'export_generated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.export.bank',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                after: ['sha256' => $hash, 'rows' => count($payslips)],
            );

            return [
                'filename' => "payroll-bank-{$run->period}-run-{$run->id}.csv",
                'content' => $content,
                'sha256' => $hash,
            ];
        });
    }

    /**
     * @return array{filename: string, content: string, sha256: string}
     */
    public function taxCsv(int $runId, int $actorUserId): array
    {
        return DB::transaction(function () use ($runId, $actorUserId): array {
            $run = $this->run($runId);
            $this->assertExportable($run);
            $employees = $run->employees()->get();

            if ($employees->isEmpty()) {
                throw new ConflictException('The run has no employee rows to export.', ['reason' => 'NO_EMPLOYEES']);
            }

            $rows = [];
            $rows[] = ['employee_id', 'period', 'taxable_cents', 'tax_withheld_cents', 'gross_cents', 'currency', 'demo_disclaimer'];
            foreach ($employees as $employee) {
                $rows[] = [
                    (string) $employee->employment_id,
                    (string) $run->period,
                    (string) $employee->taxable_cents,
                    (string) $employee->tax_cents,
                    (string) $employee->gross_cents,
                    (string) $this->currency($run),
                    'NOT_FOR_FILING',
                ];
            }

            $content = $this->csv($rows, '# TAX RETURN PREPARATION - CSV ADAPTER ONLY; NOT A TAX FILING AND NOT LEGALLY CERTIFIED');
            $hash = hash('sha256', $content);

            $run->forceFill([
                'tax_export_hash' => $hash,
                'export_generated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.export.tax',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                after: ['sha256' => $hash, 'rows' => count($employees)],
            );

            return [
                'filename' => "payroll-tax-{$run->period}-run-{$run->id}.csv",
                'content' => $content,
                'sha256' => $hash,
            ];
        });
    }

    private function run(int $runId): PayrollRun
    {
        $run = PayrollRun::query()
            ->whereKey($runId)
            ->first();

        if ($run === null) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        return $run;
    }

    private function assertExportable(PayrollRun $run): void
    {
        $status = $run->status instanceof PayrollRunStatus
            ? $run->status
            : PayrollRunStatus::from((string) $run->status);

        if (! in_array($status, [PayrollRunStatus::APPROVED, PayrollRunStatus::PUBLISHED, PayrollRunStatus::PAID, PayrollRunStatus::CLOSED], true)) {
            throw new ConflictException('Exports are available after approval only.', [
                'reason' => 'RUN_NOT_EXPORTABLE',
                'status' => $status->value,
            ]);
        }
    }

    private function currency(PayrollRun $run): string
    {
        $scheme = \App\Modules\Payroll\Models\PayrollScheme::query()
            ->whereKey($run->scheme_id)
            ->first();

        return $scheme !== null ? (string) $scheme->currency : 'CNY';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows, string $comment): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to build CSV export.');
        }

        // UTF-8 BOM for Excel compatibility.
        fwrite($handle, "\xEF\xBB\xBF");
        fwrite($handle, $comment."\n");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\', "\n");
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}

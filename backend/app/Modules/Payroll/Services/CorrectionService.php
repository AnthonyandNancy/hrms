<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\PayrollRun;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class CorrectionService
{
    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Create a correction run tied to the original. Input is re-snapshotted;
     * calculation is delta-only against the original plus all prior published
     * corrections. YTD is applied when this correction is published.
     */
    public function create(int $tenantId, int $originalRunId, int $actorUserId, string $period, string $reason): PayrollRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new ValidationException(['reason' => ['A correction reason of at least 10 characters is required.']]);
        }

        return DB::transaction(function () use ($tenantId, $originalRunId, $actorUserId, $period, $reason): PayrollRun {
            $original = PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($originalRunId)
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
            }

            $status = $original->status instanceof PayrollRunStatus
                ? $original->status
                : PayrollRunStatus::from((string) $original->status);

            if (! in_array($status, [PayrollRunStatus::PUBLISHED, PayrollRunStatus::PAID, PayrollRunStatus::CLOSED], true)) {
                throw new ConflictException('Corrections start from a published, paid or closed original run.', [
                    'reason' => 'ORIGINAL_NOT_PUBLISHED',
                    'status' => $status->value,
                ]);
            }

            $correctionPeriod = $this->periodDetails($period);

            $existing = PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->where('correction_of_run_id', $originalRunId)
                ->where('period', $period)
                ->where('run_type', PayrollRunType::CORRECTION->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $run = PayrollRun::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $original->legal_entity_id,
                'scheme_id' => $original->scheme_id,
                'period' => $period,
                'earning_period_start' => $original->earning_period_start,
                'earning_period_end' => $original->earning_period_end,
                'posting_period' => $correctionPeriod['posting'],
                'pay_date' => $correctionPeriod['pay_date'],
                'cutoff_at' => $this->clock->now(),
                'status' => PayrollRunStatus::DRAFT,
                'run_type' => PayrollRunType::CORRECTION,
                'correction_of_run_id' => $originalRunId,
                'prepared_by' => $actorUserId,
                'rule_version' => $original->rule_version,
                'snapshot_version' => 1,
            ]);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.correction.created',
                aggregateType: 'payroll_run',
                aggregateId: (int) $run->id,
                after: [
                    'correction_of_run_id' => $originalRunId,
                    'period' => $period,
                    'reason' => $reason,
                ],
            );

            // The contract requires corrections to be references to original
            // employment rows, not a fresh population. Build the same set.
            $this->snapshots->lockForCorrection($run, $actorUserId);

            return $run->refresh();
        });
    }

    /**
     * @return array{from: string, to: string, posting: string, pay_date: string}
     */
    private function periodDetails(string $period): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches) !== 1) {
            throw new ValidationException(['period' => ['The correction period must be YYYY-MM.']]);
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return [
            'from' => $start,
            'to' => (string) $end,
            'posting' => $period,
            'pay_date' => (string) $end,
        ];
    }
}

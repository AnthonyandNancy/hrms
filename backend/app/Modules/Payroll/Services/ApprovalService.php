<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Review, approval (with strict SOD), publication and payment marking.
 * Approver identity is never inferred from a preparation actor; every step is
 * recorded as a distinct user and the three roles must be mutually exclusive.
 */
final class ApprovalService
{
    public function __construct(
        private readonly PayrollStateMachine $stateMachine,
        private readonly PayrollYtdService $ytd,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function review(int $tenantId, int $runId, int $actorUserId, string $comment = ''): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $comment): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
            $this->assertSod($run, $actorUserId, 'review');

            return $this->stateMachine->transition(
                $run,
                PayrollRunStatus::REVIEWED,
                ['reviewed_by' => $actorUserId, 'reviewed_at' => $this->clock->now()],
                ['comment_present' => trim($comment) !== ''],
            );
        });
    }

    public function approve(int $tenantId, int $runId, int $actorUserId, string $comment = ''): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $comment): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
            $this->assertSod($run, $actorUserId, 'approve');

            return $this->stateMachine->transition(
                $run,
                PayrollRunStatus::APPROVED,
                ['approved_by' => $actorUserId, 'approved_at' => $this->clock->now()],
            );
        });
    }

    /**
     * Publish is the irreversible visibility step: pay slips become readable
     * and financially immutable, and YTD is folded exactly once.
     */
    public function publish(int $tenantId, int $runId, int $actorUserId, string $reason = ''): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $reason): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
            $this->assertSod($run, $actorUserId, 'publish');

            // Fold YTD and stamp payslips while the run is still APPROVED so
            // the DB immutability triggers on PUBLISHED rows are not tripped.
            $employees = $run->employees()->get();

            foreach ($employees as $employee) {
                if ($employee->ytd_applied) {
                    continue;
                }

                $this->ytd->applyForPublication($run, $employee);
            }

            $now = $this->clock->now();

            Payslip::query()
                ->where('tenant_id', $tenantId)
                ->where('run_id', $runId)
                ->whereNull('published_at')
                ->update(['published_at' => $now]);

            $run = $this->stateMachine->transition(
                $run,
                PayrollRunStatus::PUBLISHED,
                [
                    'published_by' => $actorUserId,
                    'published_at' => $now,
                ],
            );

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.run.published',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => PayrollRunStatus::APPROVED->value],
                after: [
                    'status' => PayrollRunStatus::PUBLISHED->value,
                    'gross_cents' => (int) $run->gross_cents,
                    'net_cents' => (int) $run->net_cents,
                    'reason_present' => trim($reason) !== '',
                ],
            );

            return $run->refresh();
        });
    }

    public function markPaid(int $tenantId, int $runId, int $actorUserId, string $reference = ''): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $reference): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);

            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ($status !== PayrollRunStatus::PUBLISHED) {
                throw new ConflictException('Only a published run can be marked paid.', [
                    'reason' => 'RUN_NOT_PAYABLE',
                    'status' => $status->value,
                ]);
            }

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.run.paid',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => PayrollRunStatus::PUBLISHED->value],
                after: [
                    'status' => PayrollRunStatus::PAID->value,
                    'reference_present' => trim($reference) !== '',
                ],
            );

            return $this->stateMachine->transition(
                $run,
                PayrollRunStatus::PAID,
                ['paid_by' => $actorUserId, 'paid_at' => $this->clock->now()],
            );
        });
    }

    public function close(int $tenantId, int $runId, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $actorUserId): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);

            $run = $this->stateMachine->transition(
                $run,
                PayrollRunStatus::CLOSED,
                ['closed_at' => $this->clock->now()],
            );

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.run.closed',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => PayrollRunStatus::PAID->value],
                after: ['status' => PayrollRunStatus::CLOSED->value],
            );

            return $run;
        });
    }

    /**
     * Cancel is allowed only before publication. Published runs are corrected,
     * never cancelled or reopened.
     */
    public function cancel(int $tenantId, int $runId, int $actorUserId, string $reason): PayrollRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new ValidationException(['reason' => ['A cancellation reason of at least 10 characters is required.']]);
        }

        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $reason): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ($status->isSealed()) {
                throw new ConflictException('Published runs cannot be cancelled; create a correction run.', [
                    'reason' => 'RUN_SEALED',
                    'status' => $status->value,
                ]);
            }

            $run = $this->stateMachine->transition(
                $run,
                PayrollRunStatus::CANCELLED,
                [
                    'cancellation_reason' => mb_substr($reason, 0, 1000),
                ],
            );

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.run.cancelled',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => $status->value],
                after: ['status' => PayrollRunStatus::CANCELLED->value, 'reason' => $reason],
            );

            return $run;
        });
    }

    private function assertSod(PayrollRun $run, int $actorUserId, string $step): void
    {
        $conflicts = [];

        foreach (['prepared_by' => 'preparer', 'reviewed_by' => 'reviewer', 'approved_by' => 'approver', 'published_by' => 'publisher'] as $field => $label) {
            if ((int) ($run->{$field} ?? 0) === $actorUserId) {
                $conflicts[] = $label;
            }
        }

        if ($conflicts !== []) {
            throw new ConflictException('Preparer, reviewer, approver and publisher must be different people.', [
                'reason' => 'SOD_CONFLICT',
                'step' => $step,
                'conflicts_with' => array_values(array_unique($conflicts)),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
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
        private readonly PayrollApprovalWorkflow $approvalWorkflow,
    ) {}

    public function review(int $runId, int $actorUserId, string $comment = ''): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId, $comment): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
            $this->assertSod($run, $actorUserId, 'review');

            return $this->stateMachine->transition(
                $run,
                PayrollRunStatus::REVIEWED,
                ['reviewed_by' => $actorUserId, 'reviewed_at' => $this->clock->now()],
                ['comment_present' => trim($comment) !== ''],
            );
        });
    }

    /**
     * Approval is not a bare status write. It opens a Workflow case, records
     * the Decision and runs the payroll approval effect, then verifies that
     * the effect actually advanced the run.
     */
    public function approve(int $runId, int $actorUserId, string $comment = ''): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId, $comment): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
            $this->assertSod($run, $actorUserId, 'approve');

            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ($status !== PayrollRunStatus::REVIEWED) {
                throw new ConflictException('Only a reviewed payroll run can be approved.', [
                    'reason' => 'RUN_NOT_APPROVABLE',
                    'status' => $status->value,
                ]);
            }

            $businessDate = $this->clock->now()->toDateString();
            $caseId = $this->approvalWorkflow->open($actorUserId, $runId, $actorUserId, $businessDate);

            $run->forceFill([
                'approval_case_id' => $caseId,
                'approval_effect_status' => 'PENDING',
            ])->save();

            $result = $this->approvalWorkflow->approve($caseId, $actorUserId, $comment, $businessDate);
            $run->forceFill(['approval_effect_status' => $result['effect_status']])->save();
            $run->refresh();

            if ($run->status !== PayrollRunStatus::APPROVED) {
                throw new ConflictException('The payroll approval effect did not approve the run.', [
                    'reason' => 'APPROVAL_EFFECT_NOT_APPLIED',
                    'case_id' => $caseId,
                    'application_status' => $result['application_status'],
                    'effect_status' => $result['effect_status'],
                ]);
            }

            return $run;
        });
    }

    /**
     * Publish is the irreversible visibility step: pay slips become readable
     * and financially immutable, and YTD is folded exactly once.
     */
    public function publish(int $runId, int $actorUserId, string $reason = ''): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId, $reason): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
            $this->assertSod($run, $actorUserId, 'publish');
            $status = $run->status instanceof PayrollRunStatus ? $run->status : PayrollRunStatus::from((string) $run->status);

            if ((string) $run->payslip_status === 'PUBLISHED') {
                return $run;
            }

            if (! in_array($status, [PayrollRunStatus::APPROVED, PayrollRunStatus::PAID], true)) {
                throw new ConflictException('Only an approved or disbursed run can publish payslips.', [
                    'reason' => 'RUN_NOT_PUBLISHABLE',
                    'status' => $status->value,
                ]);
            }

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
                ->where('run_id', $runId)
                ->whereNull('published_at')
                ->update(['published_at' => $now]);

            $columns = [
                'payslip_status' => 'PUBLISHED',
                'published_by' => $actorUserId,
                'published_at' => $now,
            ];

            if ($status === PayrollRunStatus::APPROVED) {
                $run = $this->stateMachine->transition($run, PayrollRunStatus::PUBLISHED, $columns);
            } else {
                $run->forceFill($columns)->save();
                $run = $run->refresh();
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.published',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => $status->value],
                after: [
                    'status' => $run->status->value,
                    'payslip_status' => 'PUBLISHED',
                    'gross_cents' => (int) $run->gross_cents,
                    'net_cents' => (int) $run->net_cents,
                    'reason_present' => trim($reason) !== '',
                ],
            );

            return $run->refresh();
        });
    }

    public function markPaid(int $runId, int $actorUserId, string $reference = '', string $manualConfirmationReason = ''): PayrollRun
    {
        if (trim($reference) === '' && mb_strlen(trim($manualConfirmationReason)) < 10) {
            throw new ValidationException(['reference' => ['Mark-paid requires a reconciliation/receipt reference or a manual confirmation reason of at least 10 characters.']]);
        }

        return DB::transaction(function () use ($runId, $actorUserId, $reference, $manualConfirmationReason): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);

            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ((string) $run->disbursement_status === 'REGISTERED') {
                return $run;
            }

            if (! in_array($status, [PayrollRunStatus::APPROVED, PayrollRunStatus::PUBLISHED, PayrollRunStatus::PAID], true)) {
                throw new ConflictException('Only an approved or published run can register disbursement.', [
                    'reason' => 'RUN_NOT_PAYABLE',
                    'status' => $status->value,
                ]);
            }

            $now = $this->clock->now();
            $columns = [
                'disbursement_status' => 'REGISTERED',
                'disbursement_reference' => trim($reference) !== '' ? mb_substr(trim($reference), 0, 160) : null,
                'disbursement_registered_by' => $actorUserId,
                'disbursement_registered_at' => $now,
                'paid_by' => $actorUserId,
                'paid_at' => $now,
            ];

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.paid',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => $status->value],
                after: [
                    'status' => $status === PayrollRunStatus::APPROVED ? PayrollRunStatus::PAID->value : $status->value,
                    'disbursement_status' => 'REGISTERED',
                    'reference_present' => trim($reference) !== '',
                    'manual_confirmation_present' => mb_strlen(trim($manualConfirmationReason)) >= 10,
                ],
            );

            if ($status === PayrollRunStatus::PAID) {
                $run->forceFill($columns)->save();

                return $run->refresh();
            }

            return $this->stateMachine->transition($run, PayrollRunStatus::PAID, $columns);
        });
    }

    public function close(int $runId, int $actorUserId): PayrollRun
    {
        return DB::transaction(function () use ($runId, $actorUserId): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
            $status = $run->status instanceof PayrollRunStatus ? $run->status : PayrollRunStatus::from((string) $run->status);

            if (! in_array($status, [PayrollRunStatus::PUBLISHED, PayrollRunStatus::PAID], true)
                || (string) $run->payslip_status !== 'PUBLISHED'
                || (string) $run->disbursement_status !== 'REGISTERED') {
                throw new ConflictException('Close requires both payslip publication and disbursement registration.', [
                    'reason' => 'RUN_CLOSE_PREREQUISITES_MISSING',
                    'status' => $status->value,
                    'payslip_status' => (string) $run->payslip_status,
                    'disbursement_status' => (string) $run->disbursement_status,
                ]);
            }

            $run = $this->stateMachine->transition(
                $run,
                PayrollRunStatus::CLOSED,
                ['closed_at' => $this->clock->now()],
            );

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.run.closed',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => $status->value],
                after: ['status' => PayrollRunStatus::CLOSED->value],
            );

            return $run;
        });
    }

    /**
     * Cancel is allowed only before publication. Published runs are corrected,
     * never cancelled or reopened.
     */
    public function cancel(int $runId, int $actorUserId, string $reason): PayrollRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new ValidationException(['reason' => ['A cancellation reason of at least 10 characters is required.']]);
        }

        return DB::transaction(function () use ($runId, $actorUserId, $reason): PayrollRun {
            $run = $this->stateMachine->lockRun($runId);
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

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Controlled reopening before publication. Requires two distinct approvers
 * plus a reason, appends a new snapshot version, changes the input hash and is
 * fully audited. Published/paid/closed runs are corrected, never reopened.
 */
final class ReopenService
{
    public function __construct(
        private readonly PayrollStateMachine $stateMachine,
        private readonly SnapshotService $snapshots,
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<int>  $approverUserIds
     */
    public function reopen(int $tenantId, int $runId, int $actorUserId, array $approverUserIds, string $reason): PayrollRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new ValidationException(['reason' => ['A reopen reason of at least 10 characters is required.']]);
        }

        $approvers = array_values(array_unique(array_map('intval', $approverUserIds)));
        $approvers = array_values(array_filter($approvers, static fn (int $id): bool => $id > 0));

        if (count($approvers) < 2) {
            throw new ValidationException(['approver_user_ids' => ['Reopen requires two distinct approvers.']]);
        }

        if (in_array($actorUserId, $approvers, true)) {
            throw new ValidationException(['approver_user_ids' => ['The requester cannot approve their own reopen.']]);
        }

        return DB::transaction(function () use ($tenantId, $runId, $actorUserId, $approvers, $reason): PayrollRun {
            $run = $this->stateMachine->lockRun($tenantId, $runId);
            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if (! $status->isPrePublish() || in_array($status, [PayrollRunStatus::DRAFT, PayrollRunStatus::INPUT_LOCKED], true)) {
                throw new ConflictException('Only a calculated, reviewed or approved run can be reopened.', [
                    'reason' => 'RUN_NOT_REOPENABLE',
                    'status' => $status->value,
                ]);
            }

            $previousHash = (string) $run->input_hash;
            $nextVersion = (int) $run->snapshot_version + 1;
            $approvals = $run->reopen_approvals_json ?? [];
            $approvals[] = [
                'requested_by' => $actorUserId,
                'approved_by' => $approvers,
                'reason' => $reason,
                'at' => $this->clock->now()->toIso8601ZuluString(),
            ];

            $run = $this->stateMachine->transition(
                $run,
                PayrollRunStatus::INPUT_LOCKED,
                [
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'calculated_at' => null,
                    'snapshot_version' => $nextVersion,
                    'reopen_count' => (int) $run->reopen_count + 1,
                    'reopen_approvals_json' => $approvals,
                    'reopened_at' => $this->clock->now(),
                ],
                ['dual_approval' => true],
            );

            // Re-snapshot from the current inputs so a changed attendance or
            // terms version produces a new, reproducible input hash.
            $newHash = $this->snapshots->lock($run, $actorUserId);

            if ($newHash === $previousHash) {
                throw new ConflictException('Reopen did not change any payroll input; nothing to recalculate.', [
                    'reason' => 'INPUT_HASH_UNCHANGED',
                    'input_hash' => $newHash,
                ]);
            }

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'payroll.run.reopened',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                before: ['status' => $status->value, 'input_hash' => $previousHash],
                after: [
                    'status' => PayrollRunStatus::INPUT_LOCKED->value,
                    'input_hash' => $newHash,
                    'snapshot_version' => $nextVersion,
                    'approved_by' => $approvers,
                    'reason' => $reason,
                ],
            );

            return $run->refresh();
        });
    }
}

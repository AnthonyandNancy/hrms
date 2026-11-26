<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayrollApprovalEffectPort;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Applies the Workflow approval effect to the payroll run. Kept separate from
 * ApprovalService so the effect can run inside Workflow's decision transaction
 * without a module cycle.
 */
final class PayrollApprovalEffectService implements PayrollApprovalEffectPort
{
    public function __construct(private readonly PayrollStateMachine $stateMachine) {}

    public function applyApproval(int $runId, int $actorUserId): void
    {
        DB::transaction(function () use ($runId, $actorUserId): void {
            $run = PayrollRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw new ConflictException('The payroll run for this approval no longer exists.', [
                    'reason' => 'PAYROLL_RUN_MISSING',
                    'run_id' => $runId,
                ]);
            }

            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ($status === PayrollRunStatus::APPROVED) {
                return;
            }

            if ($status !== PayrollRunStatus::REVIEWED) {
                throw new ConflictException('Only a reviewed payroll run can be approved.', [
                    'reason' => 'RUN_NOT_APPROVABLE',
                    'status' => $status->value,
                ]);
            }

            $this->stateMachine->transition(
                $run,
                PayrollRunStatus::APPROVED,
                ['approved_by' => $actorUserId, 'approved_at' => now()],
            );
        });
    }
}

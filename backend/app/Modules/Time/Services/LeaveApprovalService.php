<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeaveRequestProjection;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Workflow approval effect for a leave case.
 *
 * Workflow still owns the decision. This service is the only Time-side writer
 * that turns an approved case into ledger facts: it releases the submit-time
 * hold and posts CONSUME exactly once. The integration owner binds it to the
 * published Workflow effect `workflow.leave.reserve` (see M10 integration.json).
 */
final class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
        private readonly PayrollFreezeGuard $freezeGuard,
    ) {}

    /**
     * @return array{case_id: int, state: string, replayed: bool}
     */
    public function applyApprovedCase(int $caseId, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($caseId, $actorUserId): array {
            /** @var LeaveRequestProjection|null $projection */
            $projection = LeaveRequestProjection::query()
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            $state = strtoupper((string) $projection->state);

            if (in_array($state, ['APPROVED', 'CONSUMED', 'REVOKED'], true)) {
                return ['case_id' => $caseId, 'state' => $state, 'replayed' => true];
            }

            if ($state !== 'SUBMITTED') {
                throw new ConflictException('The leave request is not awaiting approval.', [
                    'reason' => 'LEAVE_REQUEST_NOT_APPROVABLE',
                    'state' => $state,
                ]);
            }

            $businessDate = $projection->from_date->format('Y-m-d');

            // Ledger money/facts belong to the leave month. Lock the same
            // durable Time month mutex used by close and all other mutations.
            $this->freezeGuard->lockAndAssertOpen(substr($businessDate, 0, 7));

            $this->ledger->consume(
                employmentId: (int) $projection->employment_id,
                leaveTypeId: (int) $projection->leave_type_id,
                minutes: (int) $projection->minutes,
                businessDate: $businessDate,
                sourceType: 'LEAVE_REQUEST',
                sourceId: (string) $caseId,
                reason: 'Leave approved',
                caseId: $caseId,
            );

            $projection->forceFill([
                'state' => 'APPROVED',
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.leave.request.approved',
                'leave_request',
                $caseId,
                after: [
                    'state' => 'APPROVED',
                    'subject_employment_id' => (int) $projection->employment_id,
                    'minutes' => (int) $projection->minutes,
                ],
            );

            return ['case_id' => $caseId, 'state' => 'APPROVED', 'replayed' => false];
        });
    }
}
